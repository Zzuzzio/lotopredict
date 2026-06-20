<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\Lottery;
use App\Parser\BrowserStolotoClient;
use App\Parser\DrawNormalizer;
use App\Parser\StolotoClient;
use App\Parser\StolotoFullArchiveFetcher;

class FetchService
{
    /** @var StolotoClient */
    private $client;

    /** @var BrowserStolotoClient|null */
    private $browserClient;

    /** @var DrawNormalizer */
    private $normalizer;

    /** @var int */
    private $backfillLimit;

    /** @var int */
    private $maxPages;

    /** @var bool */
    private $useBrowser;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->client = new StolotoClient();
        $this->normalizer = new DrawNormalizer();
        $this->backfillLimit = (int) $config['backfill_limit'];
        $this->maxPages = (int) $config['archive_max_pages'];
        $this->useBrowser = !empty($config['use_browser_parser']);

        if ($this->useBrowser) {
            $this->browserClient = new BrowserStolotoClient();
            if (!$this->browserClient->isAvailable()) {
                $this->useBrowser = false;
            }
        }
    }

    /**
     * Full archive from first draw (long-running, CLI only).
     *
     * @return array
     */
    public function fetchLotteryFullArchive($slug, $importOnly = false, $curlOnly = false)
    {
        $lottery = Lottery::findBySlug($slug);
        if ($lottery === null) {
            return ['saved' => 0, 'skipped' => 0, 'errors' => 1, 'pages' => 0, 'source' => 'none'];
        }

        $lotteryConfig = $this->getLotteryConfig($slug);
        $pageSize = isset($lotteryConfig['archive_page_size']) ? (int) $lotteryConfig['archive_page_size'] : 50;
        $storageDir = dirname(__DIR__, 2) . '/storage/logs';
        $jsonlFile = $storageDir . '/archive_' . $lottery['stoloto_game'] . '.jsonl';
        $progressFile = $storageDir . '/archive_' . $lottery['stoloto_game'] . '_progress.json';

        $source = 'browser_full';
        $archiveResult = [
            'draws' => [],
            'pages_fetched' => 0,
            'scroll_rounds' => 0,
            'stopped_reason' => 'import_only',
            'jsonl' => $jsonlFile,
            'total_draws' => 0,
        ];

        if (!$importOnly) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $preferCurl = !empty($config['full_archive_prefer_curl']);
            $useBrowser = $this->useBrowser && $this->browserClient !== null && !$curlOnly && !$preferCurl;

            if ($useBrowser) {
                $browserOpts = $this->getBrowserOverrides($lotteryConfig);
                $archiveResult = $this->browserClient->fetchFullArchive(
                    $lottery['stoloto_game'],
                    $pageSize,
                    $browserOpts
                );
                $browserFailed = in_array(
                    $archiveResult['stopped_reason'],
                    ['exception', 'browser_error', 'launch_error', 'invalid_json'],
                    true
                ) || ((int) $archiveResult['total_draws'] === 0 && empty($archiveResult['draws']));

                if ($browserFailed) {
                    $this->log('Browser full archive failed (' . $archiveResult['stopped_reason'] . '), falling back to curl');
                    $useBrowser = false;
                }
            }

            if (!$useBrowser) {
                $source = 'curl_full';
                $seedMax = Draw::getMaxDrawNumber((int) $lottery['id']);
                $fetcher = new StolotoFullArchiveFetcher($this->getFetcherOverrides($lotteryConfig));
                $curlResult = $fetcher->fetch($lottery['stoloto_game'], $jsonlFile, $progressFile, $seedMax);
                $archiveResult = [
                    'draws' => [],
                    'pages_fetched' => $curlResult['pages_fetched'],
                    'scroll_rounds' => 0,
                    'stopped_reason' => $curlResult['stopped_reason'],
                    'jsonl' => $curlResult['jsonl'],
                    'total_draws' => $curlResult['total_draws'],
                ];
            }
        }

        $saved = 0;
        $skipped = 0;
        $errors = 0;
        $fetched = 0;

        if (!empty($archiveResult['jsonl']) && is_file($archiveResult['jsonl'])) {
            $import = $this->importDrawsFromJsonl($lottery, $lotteryConfig, $archiveResult['jsonl']);
            $saved = $import['saved'];
            $skipped = $import['skipped'];
            $errors = $import['errors'];
            $fetched = $import['fetched'];
        } else {
            foreach ($archiveResult['draws'] as $raw) {
                $result = $this->saveRawDraw($lottery, $raw, $lotteryConfig);
                $saved += $result['saved'];
                $skipped += $result['skipped'];
                $errors += $result['errors'];
                $fetched++;
            }
        }

        $this->logFetchResult(
            $slug,
            $archiveResult['pages_fetched'],
            $archiveResult['stopped_reason'],
            $fetched,
            $source
        );

        return [
            'saved' => $saved,
            'skipped' => $skipped,
            'errors' => $errors,
            'pages' => $archiveResult['pages_fetched'],
            'scroll_rounds' => isset($archiveResult['scroll_rounds']) ? $archiveResult['scroll_rounds'] : 0,
            'fetched' => $fetched,
            'stopped_reason' => $archiveResult['stopped_reason'],
            'source' => $source,
            'jsonl' => isset($archiveResult['jsonl']) ? $archiveResult['jsonl'] : null,
            'min_draw' => $this->getMinDrawNumber((int) $lottery['id']),
        ];
    }

    /**
     * @return array{saved: int, skipped: int, errors: int, fetched: int}
     */
    private function importDrawsFromJsonl(array $lottery, array $lotteryConfig, $jsonlPath)
    {
        $saved = 0;
        $skipped = 0;
        $errors = 0;
        $fetched = 0;

        $handle = fopen($jsonlPath, 'r');
        if ($handle === false) {
            return ['saved' => 0, 'skipped' => 0, 'errors' => 1, 'fetched' => 0];
        }

        // Batch upserts in transactions — without this each row commits separately
        // (per-row fsync), making large imports (160k+) take ~45+ minutes.
        $pdo = \App\Database\Connection::get();
        $batchSize = 2000;
        $pdo->beginTransaction();

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $raw = json_decode($line, true);
            if (!is_array($raw)) {
                $errors++;
                continue;
            }

            $fetched++;
            $result = $this->saveRawDraw($lottery, $raw, $lotteryConfig);
            $saved += $result['saved'];
            $skipped += $result['skipped'];
            $errors += $result['errors'];

            if ($fetched % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $this->log('Import progress: ' . $fetched . ' lines from ' . basename($jsonlPath));
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        fclose($handle);

        return ['saved' => $saved, 'skipped' => $skipped, 'errors' => $errors, 'fetched' => $fetched];
    }

    private function getMinDrawNumber($lotteryId)
    {
        return Draw::getMinDrawNumber($lotteryId);
    }

    /**
     * @param array $lotteryConfig
     * @return array{parallel?: int, page_size?: int, delay_ms?: int}
     */
    private function getFetcherOverrides(array $lotteryConfig)
    {
        $overrides = [];

        if (!empty($lotteryConfig['fetch_parallel'])) {
            $overrides['parallel'] = (int) $lotteryConfig['fetch_parallel'];
        }
        if (!empty($lotteryConfig['fetch_page_size'])) {
            $overrides['page_size'] = (int) $lotteryConfig['fetch_page_size'];
        }
        if (isset($lotteryConfig['fetch_delay_ms'])) {
            $overrides['delay_ms'] = (int) $lotteryConfig['fetch_delay_ms'];
        }

        return $overrides;
    }

    /**
     * @param array $lotteryConfig
     * @return array{parallel?: int, delay_ms?: int, target_min?: int}
     */
    private function getBrowserOverrides(array $lotteryConfig)
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $overrides = [
            'parallel' => isset($config['full_archive_browser_parallel'])
                ? (int) $config['full_archive_browser_parallel']
                : 30,
            'delay_ms' => isset($config['full_archive_browser_delay_ms'])
                ? (int) $config['full_archive_browser_delay_ms']
                : 600,
        ];

        if (!empty($lotteryConfig['fetch_browser_parallel'])) {
            $overrides['parallel'] = (int) $lotteryConfig['fetch_browser_parallel'];
        }
        if (isset($lotteryConfig['fetch_browser_delay_ms'])) {
            $overrides['delay_ms'] = (int) $lotteryConfig['fetch_browser_delay_ms'];
        }
        if (!empty($lotteryConfig['first_draw_number'])) {
            $overrides['target_min'] = (int) $lotteryConfig['first_draw_number'];
        }

        return $overrides;
    }

    /**
     * @return array
     */
    public function fetchLottery($slug, $fullBackfill = false)
    {
        $lottery = Lottery::findBySlug($slug);
        if ($lottery === null) {
            return ['saved' => 0, 'skipped' => 0, 'errors' => 1, 'pages' => 0, 'source' => 'none'];
        }

        $lotteryConfig = $this->getLotteryConfig($slug);
        $pageSize = isset($lotteryConfig['archive_page_size']) ? (int) $lotteryConfig['archive_page_size'] : 50;
        $maxPages = $fullBackfill
            ? min($this->maxPages, (int) ceil($this->backfillLimit / $pageSize))
            : 2;

        $source = 'curl';
        $archiveResult = null;

        if ($this->useBrowser && $this->browserClient !== null) {
            $archiveResult = $this->browserClient->fetchArchiveAll(
                $lottery['stoloto_game'],
                $maxPages,
                $pageSize
            );
            $source = 'browser';

            if ($archiveResult['draws'] === [] && $archiveResult['stopped_reason'] !== 'no_more') {
                $archiveResult = $this->client->fetchArchiveAll(
                    $lottery['stoloto_game'],
                    $maxPages,
                    $pageSize
                );
                $source = 'curl_fallback';
            }
        } else {
            $archiveResult = $this->client->fetchArchiveAll(
                $lottery['stoloto_game'],
                $maxPages,
                $pageSize
            );
        }

        $saved = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($archiveResult['draws'] as $raw) {
            $result = $this->saveRawDraw($lottery, $raw, $lotteryConfig);
            $saved += $result['saved'];
            $skipped += $result['skipped'];
            $errors += $result['errors'];
        }

        $this->logFetchResult(
            $slug,
            $archiveResult['pages_fetched'],
            $archiveResult['stopped_reason'],
            count($archiveResult['draws']),
            $source
        );

        return [
            'saved' => $saved,
            'skipped' => $skipped,
            'errors' => $errors,
            'pages' => $archiveResult['pages_fetched'],
            'fetched' => count($archiveResult['draws']),
            'stopped_reason' => $archiveResult['stopped_reason'],
            'source' => $source,
        ];
    }

    /**
     * @return array
     */
    private function saveRawDraw(array $lottery, array $raw, array $lotteryConfig)
    {
        $options = [];
        if (!empty($lotteryConfig['bonus_count'])) {
            $options['bonus_count'] = (int) $lotteryConfig['bonus_count'];
            if (!empty($lotteryConfig['bonus_max_number'])) {
                $options['bonus_max_number'] = (int) $lotteryConfig['bonus_max_number'];
            }
        }

        $normalized = $this->normalizer->normalize(
            $raw,
            (int) $lottery['numbers_count'],
            $options
        );

        if ($normalized === null) {
            return ['saved' => 0, 'skipped' => 0, 'errors' => 1];
        }

        $ok = Draw::upsert(
            (int) $lottery['id'],
            $normalized['draw_number'],
            $normalized['draw_date'],
            $normalized['numbers']
        );

        return ['saved' => $ok ? 1 : 0, 'skipped' => $ok ? 0 : 1, 'errors' => 0];
    }

    private function getLotteryConfig($slug)
    {
        $lotteries = require dirname(__DIR__, 2) . '/config/lotteries.php';
        return isset($lotteries[$slug]) ? $lotteries[$slug] : [];
    }

    private function logFetchResult($slug, $pages, $reason, $fetched, $source)
    {
        $line = sprintf(
            '[%s] %s: source=%s pages=%d fetched=%d stopped=%s',
            date('Y-m-d H:i:s'),
            $slug,
            $source,
            $pages,
            $fetched,
            $reason
        );
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        file_put_contents($config['log_path'], $line . PHP_EOL, FILE_APPEND);
    }

    private function log($message)
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        file_put_contents($config['log_path'], '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * @return array
     */
    public function fetchAllFullArchive($importOnly = false, $curlOnly = false)
    {
        $results = [];
        $lotteries = Lottery::all();
        $total = count($lotteries);

        foreach ($lotteries as $index => $lottery) {
            $results[$lottery['slug']] = $this->fetchLotteryFullArchive($lottery['slug'], $importOnly, $curlOnly);

            if ($index < $total - 1) {
                sleep(10);
            }
        }

        return $results;
    }

    /**
     * @return array
     */
    public function fetchAll($fullBackfill = false)
    {
        $results = [];
        $lotteries = Lottery::all();
        $total = count($lotteries);

        foreach ($lotteries as $index => $lottery) {
            $results[$lottery['slug']] = $this->fetchLottery($lottery['slug'], $fullBackfill);

            if ($index < $total - 1) {
                sleep(5);
            }
        }

        return $results;
    }
}
