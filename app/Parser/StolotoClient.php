<?php

namespace App\Parser;

class StolotoClient
{
    private $logPath;
    private $cookiePath;
    private $delayMs;
    private $retries;
    private $sessionWarmed = false;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->logPath = $config['log_path'];
        $this->cookiePath = $config['cookie_path'];
        $this->delayMs = (int) $config['request_delay_ms'];
        $this->retries = (int) $config['request_retries'];

        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Fetch archive pages the same way the site infinite scroll does (page=1,2,3...).
     *
     * @return array{draws: array, pages_fetched: int, stopped_reason: string}
     */
    public function fetchArchiveAll($stolotoGame, $maxPages = 20, $countPerPage = 50)
    {
        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $this->warmupSession($archiveUrl);

        $allDraws = [];
        $seenNumbers = [];
        $pagesFetched = 0;
        $stoppedReason = 'complete';

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $this->fetchArchivePage($stolotoGame, $page, $countPerPage, $archiveUrl);

            if ($result['draws'] === []) {
                $stoppedReason = $page === 1 ? 'api_error' : 'page_blocked';
                break;
            }
            $pagesFetched++;

            foreach ($result['draws'] as $draw) {
                $number = isset($draw['number']) ? (int) $draw['number'] : 0;
                if ($number > 0 && !isset($seenNumbers[$number])) {
                    $seenNumbers[$number] = true;
                    $allDraws[] = $draw;
                }
            }

            if (!$result['has_more']) {
                $stoppedReason = 'no_more';
                break;
            }

            if (count($result['draws']) < $countPerPage) {
                $stoppedReason = 'short_page';
                break;
            }
        }

        return [
            'draws' => $allDraws,
            'pages_fetched' => $pagesFetched,
            'stopped_reason' => $stoppedReason,
        ];
    }

    /**
     * @return array{draws: array, has_more: bool}
     */
    public function fetchArchivePage($stolotoGame, $page = 1, $count = 30, $archiveUrl = null)
    {
        if ($archiveUrl === null) {
            $archiveUrl = $this->getArchiveUrl($stolotoGame);
        }

        $url = sprintf(
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/archive?game=%s&count=%d&page=%d',
            urlencode($stolotoGame),
            $count,
            $page
        );

        $data = $this->requestJson($url, $archiveUrl);

        if ($data === null) {
            return ['draws' => [], 'has_more' => false];
        }

        return [
            'draws' => $this->extractDraws($data),
            'has_more' => !empty($data['hasMore']),
        ];
    }

    /**
     * @return array
     */
    public function fetchArchive($stolotoGame, $page = 1, $count = 30)
    {
        $result = $this->fetchArchivePage($stolotoGame, $page, $count);
        return $result['draws'];
    }

    /**
     * @return array|null
     */
    public function fetchDrawByNumber($stolotoGame, $drawNumber)
    {
        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $url = sprintf(
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/%d?game=%s',
            (int) $drawNumber,
            urlencode($stolotoGame)
        );

        $data = $this->requestJson($url, $archiveUrl);
        if ($data === null) {
            return null;
        }

        return $this->normalizeSingleDraw($data);
    }

    /**
     * Parallel fetch by draw numbers (curl_multi). Key = draw number, value = draw array or null.
     *
     * @param int[] $drawNumbers
     * @return array<int, array|null>
     */
    public function fetchDrawsByNumbersBatch($stolotoGame, array $drawNumbers)
    {
        if ($drawNumbers === []) {
            return [];
        }

        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($drawNumbers as $n) {
                $out[(int) $n] = $this->fetchDrawByNumber($stolotoGame, $n);
            }
            return $out;
        }

        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($drawNumbers as $n) {
            $n = (int) $n;
            $url = sprintf(
                'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/%d?game=%s',
                $n,
                urlencode($stolotoGame)
            );
            $handles[$n] = $this->createCurlHandle($url, $archiveUrl);
            curl_multi_add_handle($mh, $handles[$n]);
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $n => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body === false || $code >= 400) {
                $results[$n] = null;
                continue;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || (isset($data['requestStatus']) && $data['requestStatus'] !== 'success')) {
                $results[$n] = null;
                continue;
            }

            $results[$n] = $this->normalizeSingleDraw($data);
        }

        curl_multi_close($mh);
        return $results;
    }

    public function warmupArchiveSession($archiveUrl)
    {
        $this->sessionWarmed = false;
        $this->deepWarmup($archiveUrl);
    }

    /**
     * Probe whether single-draw API works (archive endpoint may be blocked separately).
     */
    public function probeSingleDraw($stolotoGame, $drawNumber)
    {
        $this->deepWarmup($this->getArchiveUrl($stolotoGame));
        return $this->fetchDrawByNumber($stolotoGame, $drawNumber);
    }

    /**
     * @return string|null
     */
    public function fetchRaw($url, $referer)
    {
        try {
            return $this->httpGet($url, $referer, false);
        } catch (\Exception $e) {
            $this->log('fetchRaw failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Archive page with session refresh (helps unlock page 2+).
     *
     * @return array{draws: array, has_more: bool}
     */
    public function fetchArchivePageFresh($stolotoGame, $page, $count = 50)
    {
        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        if ($page > 1) {
            $this->warmupArchiveSession($archiveUrl);
            usleep(500000);
        }

        return $this->fetchArchivePage($stolotoGame, $page, $count, $archiveUrl);
    }

    private function normalizeSingleDraw(array $data)
    {
        if (isset($data['draw']) && is_array($data['draw'])) {
            return $data['draw'];
        }

        if (isset($data['draws'][0]) && is_array($data['draws'][0])) {
            return $data['draws'][0];
        }

        return is_array($data) && isset($data['number']) ? $data : null;
    }

    private function createCurlHandle($url, $referer)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => $this->cookiePath,
            CURLOPT_COOKIEFILE => $this->cookiePath,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Origin: https://www.stoloto.ru',
                'Referer: ' . $referer,
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
            ],
        ]);

        return $ch;
    }

    private function warmupSession($archiveUrl)
    {
        if ($this->sessionWarmed) {
            return;
        }

        $this->deepWarmup($archiveUrl);
    }

    private function deepWarmup($archiveUrl)
    {
        try {
            $this->httpGet('https://www.stoloto.ru/', 'https://www.stoloto.ru/', false, 'html');
            usleep(400000);
            $this->httpGet($archiveUrl, 'https://www.stoloto.ru/', false, 'html');
            usleep(400000);
            $this->sessionWarmed = true;
        } catch (\Exception $e) {
            $this->log('Session warmup failed: ' . $e->getMessage());
        }
    }

    public function clearSession()
    {
        $this->resetCookies();
    }

    private function resetCookies()
    {
        if (is_file($this->cookiePath)) {
            @unlink($this->cookiePath);
        }
        $this->sessionWarmed = false;
        $this->log('Cookie jar reset after API block');
    }

    private function getArchiveUrl($stolotoGame)
    {
        $lotteries = require dirname(__DIR__, 2) . '/config/lotteries.php';
        foreach ($lotteries as $lottery) {
            if ($lottery['stoloto_game'] === $stolotoGame) {
                return $lottery['archive_url'];
            }
        }

        return 'https://www.stoloto.ru/archive';
    }

    /**
     * @return array|null
     */
    private function requestJson($url, $referer)
    {
        $attempt = 0;

        while ($attempt < $this->retries) {
            $attempt++;

            try {
                $body = $this->httpGet($url, $referer, true);
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    $this->log('Invalid JSON from ' . $url);
                    return null;
                }

                if (isset($data['requestStatus']) && $data['requestStatus'] !== 'success') {
                    $errors = isset($data['errors']) ? implode(', ', (array) $data['errors']) : 'unknown';
                    $this->log('API error for ' . $url . ' (attempt ' . $attempt . '): ' . $errors);

                    if ($attempt < $this->retries) {
                        $this->sleepExtra(3000 * $attempt);
                        continue;
                    }

                    return null;
                }

                return $data;
            } catch (\Exception $e) {
                $this->log('HTTP error for ' . $url . ' (attempt ' . $attempt . '): ' . $e->getMessage());

                if (strpos($e->getMessage(), '403') !== false && $attempt === 1) {
                    $this->resetCookies();
                    $this->deepWarmup($referer);
                }

                if ($attempt < $this->retries) {
                    $this->sleepExtra(3000 * $attempt);
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    private function httpGet($url, $referer, $applyDelay, $mode = 'json')
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension required');
        }

        $accept = $mode === 'html'
            ? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            : 'application/json, text/plain, */*';

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: ' . $accept,
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];

        if ($mode === 'json') {
            $headers[] = 'Origin: https://www.stoloto.ru';
            $headers[] = 'Referer: ' . $referer;
            $headers[] = 'Sec-Fetch-Dest: empty';
            $headers[] = 'Sec-Fetch-Mode: cors';
            $headers[] = 'Sec-Fetch-Site: same-origin';
        } else {
            $headers[] = 'Referer: ' . $referer;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => $this->cookiePath,
            CURLOPT_COOKIEFILE => $this->cookiePath,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($applyDelay && $this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }

        if ($body === false) {
            throw new \RuntimeException($error ?: 'curl error');
        }

        if ($code >= 400) {
            throw new \RuntimeException('HTTP ' . $code);
        }

        return $body;
    }

    private function sleepExtra($ms)
    {
        usleep($ms * 1000);
    }

    /**
     * @param array $data
     * @return array
     */
    private function extractDraws(array $data)
    {
        if (isset($data['draws']) && is_array($data['draws'])) {
            return $data['draws'];
        }

        if (isset($data['data']['draws']) && is_array($data['data']['draws'])) {
            return $data['data']['draws'];
        }

        if (isset($data['archive']) && is_array($data['archive'])) {
            return $data['archive'];
        }

        return [];
    }

    private function log($message)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logPath, $line, FILE_APPEND);
    }
}
