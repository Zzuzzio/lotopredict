<?php

namespace App\Parser;

class StolotoArchiveExporter
{
    /** @var StolotoClient */
    private $client;

    /** @var string */
    private $logPath;

    public function __construct(StolotoClient $client = null)
    {
        $this->client = $client ?: new StolotoClient();
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->logPath = $config['log_path'];
    }

    /**
     * Try official «Скачать архив» endpoints. Returns path to saved file or null.
     *
     * @return string|null
     */
    public function tryDownload($stolotoGame, $saveDir)
    {
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }

        $candidates = [
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/archive/download?game=%s',
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/archive/export?game=%s',
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/export?game=%s&format=csv',
            'https://www.stoloto.ru/p/api/mobile/api/v35/service/draws/export?game=%s&format=xlsx',
            'https://www.stoloto.ru/files/archive/%s.csv',
            'https://www.stoloto.ru/files/archive/%s.xlsx',
        ];

        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $this->client->warmupArchiveSession($archiveUrl);

        foreach ($candidates as $pattern) {
            $url = sprintf($pattern, urlencode($stolotoGame));
            $saved = $this->tryUrl($url, $archiveUrl, $saveDir, $stolotoGame);
            if ($saved !== null) {
                return $saved;
            }
        }

        $html = $this->client->fetchRaw($archiveUrl, 'https://www.stoloto.ru/');
        if ($html !== null) {
            if (preg_match('/href="([^"]+(?:export|download|archive)[^"]*\.(?:csv|xlsx|zip)[^"]*)"/i', $html, $m)) {
                $href = html_entity_decode($m[1]);
                if (strpos($href, 'http') !== 0) {
                    $href = 'https://www.stoloto.ru' . $href;
                }
                $saved = $this->tryUrl($href, $archiveUrl, $saveDir, $stolotoGame);
                if ($saved !== null) {
                    return $saved;
                }
            }
        }

        $this->log("No official export URL found for {$stolotoGame}");
        return null;
    }

    /**
     * @return array<int, array>
     */
    public function parseFile($filePath)
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return $this->parseCsv($filePath);
        }

        if ($ext === 'xlsx') {
            $this->log('XLSX found but PHP has no xlsx reader — convert to CSV manually or install ext');
            return [];
        }

        if ($ext === 'zip') {
            return $this->parseZip($filePath);
        }

        return [];
    }

    /**
     * @return array<int, array>
     */
    private function parseCsv($filePath)
    {
        $draws = [];
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return $draws;
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return $draws;
        }

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $header = array_map('mb_strtolower', str_getcsv(trim($firstLine), $delimiter));

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $draw = $this->csvRowToDraw($header, $row);
            if ($draw !== null) {
                $draws[] = $draw;
            }
        }

        fclose($handle);
        return $draws;
    }

    /**
     * @return array<int, array>
     */
    private function parseZip($zipPath)
    {
        if (!class_exists('ZipArchive')) {
            return [];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [];
        }

        $draws = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -4) === '.csv') {
                $tmp = sys_get_temp_dir() . '/stoloto_' . basename($name);
                copy('zip://' . $zipPath . '#' . $name, $tmp);
                $draws = array_merge($draws, $this->parseCsv($tmp));
                @unlink($tmp);
                break;
            }
        }

        $zip->close();
        return $draws;
    }

    /**
     * @param array<int, string> $header
     * @param array<int, string> $row
     * @return array|null
     */
    private function csvRowToDraw(array $header, array $row)
    {
        $map = [];
        foreach ($header as $i => $col) {
            $map[$col] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        $number = 0;
        foreach (['тираж', 'номер', 'number', 'draw'] as $key) {
            if (!empty($map[$key])) {
                $number = (int) preg_replace('/\D/', '', $map[$key]);
                break;
            }
        }

        if ($number <= 0) {
            return null;
        }

        $numbers = [];
        foreach ($map as $key => $val) {
            if (preg_match('/^(число|ball|n)\s*(\d+)$/u', $key, $m) || preg_match('/^(\d+)$/', $key)) {
                $n = (int) preg_replace('/\D/', '', $val);
                if ($n > 0) {
                    $numbers[] = $n;
                }
            }
        }

        if ($numbers === [] && (isset($map['выпавшие числа']) || isset($map['числа']))) {
            $rawNums = isset($map['числа']) ? $map['числа'] : $map['выпавшие числа'];
            preg_match_all('/\d+/', $rawNums, $matches);
            $numbers = array_map('intval', $matches[0]);
        }

        $date = '';
        foreach (['дата', 'date', 'дата тиража'] as $key) {
            if (!empty($map[$key])) {
                $date = $map[$key];
                break;
            }
        }

        return [
            'number' => $number,
            'date' => $date,
            'combination' => ['structured' => $numbers],
        ];
    }

    private function tryUrl($url, $referer, $saveDir, $game)
    {
        $body = $this->client->fetchRaw($url, $referer);
        if ($body === null || strlen($body) < 100) {
            return null;
        }

        if ($body[0] === '{' || $body[0] === '[') {
            return null;
        }

        $ext = 'csv';
        if (substr($body, 0, 2) === 'PK') {
            $ext = 'zip';
        }

        $path = $saveDir . '/export_' . $game . '.' . $ext;
        file_put_contents($path, $body);
        $this->log("Downloaded archive export: {$url} -> {$path} (" . strlen($body) . " bytes)");

        return $path;
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

    private function log($message)
    {
        file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
