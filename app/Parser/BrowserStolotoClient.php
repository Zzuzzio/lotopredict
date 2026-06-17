<?php

namespace App\Parser;

class BrowserStolotoClient
{
    private $logPath;
    private $browserDir;
    private $runScript;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->logPath = $config['log_path'];
        $this->browserDir = dirname(__DIR__, 2) . '/browser';
        $this->runScript = $this->browserDir . '/run.sh';
    }

    /**
     * Full archive via scroll + network intercept (may run 1–2+ hours).
     *
     * @return array{draws: array, pages_fetched: int, scroll_rounds: int, stopped_reason: string, jsonl: string|null, total_draws: int}
     */
    public function fetchFullArchive($stolotoGame, $countPerPage = 50)
    {
        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $delay = isset($config['full_archive_delay_ms']) ? (int) $config['full_archive_delay_ms'] : 2500;
        $storageDir = dirname(__DIR__, 2) . '/storage/logs';

        $jsonlFile = $storageDir . '/archive_' . $stolotoGame . '.jsonl';
        $progressFile = $storageDir . '/archive_' . $stolotoGame . '_progress.json';
        $metaFile = $storageDir . '/browser_full_' . $stolotoGame . '_' . time() . '.json';

        $resume = is_file($jsonlFile) && filesize($jsonlFile) > 0 ? '--resume' : '';

        $cmd = sprintf(
            '%s --mode=full --game=%s --url=%s --count=%d --delay=%d --jsonl=%s --progress=%s %s --out=%s 2>>%s',
            escapeshellarg($this->runScript),
            escapeshellarg($stolotoGame),
            escapeshellarg($archiveUrl),
            (int) $countPerPage,
            (int) $delay,
            escapeshellarg($jsonlFile),
            escapeshellarg($progressFile),
            $resume,
            escapeshellarg($metaFile),
            escapeshellarg($this->logPath)
        );

        $this->log('Browser FULL archive start: ' . $stolotoGame);

        exec($cmd, $output, $exitCode);

        if (!is_file($metaFile)) {
            $this->log('Browser full fetch failed: no meta file, exit=' . $exitCode);
            return [
                'draws' => [],
                'pages_fetched' => 0,
                'scroll_rounds' => 0,
                'stopped_reason' => 'browser_error',
                'jsonl' => is_file($jsonlFile) ? $jsonlFile : null,
                'total_draws' => 0,
            ];
        }

        $raw = file_get_contents($metaFile);
        @unlink($metaFile);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'draws' => [],
                'pages_fetched' => 0,
                'scroll_rounds' => 0,
                'stopped_reason' => 'invalid_json',
                'jsonl' => is_file($jsonlFile) ? $jsonlFile : null,
                'total_draws' => 0,
            ];
        }

        $draws = [];
        if (!empty($data['draws'])) {
            $draws = $data['draws'];
        }

        $totalDraws = isset($data['total_draws']) ? (int) $data['total_draws'] : count($draws);
        if ($totalDraws === 0 && is_file($jsonlFile)) {
            $totalDraws = $this->countJsonlLines($jsonlFile);
        }

        $this->log(sprintf(
            'Browser FULL done: %s pages=%d scroll=%d draws=%d min=%s max=%s stopped=%s',
            $stolotoGame,
            isset($data['pages_fetched']) ? $data['pages_fetched'] : 0,
            isset($data['scroll_rounds']) ? $data['scroll_rounds'] : 0,
            $totalDraws,
            isset($data['min_draw']) ? $data['min_draw'] : '-',
            isset($data['max_draw']) ? $data['max_draw'] : '-',
            isset($data['stopped_reason']) ? $data['stopped_reason'] : 'unknown'
        ));

        return [
            'draws' => $draws,
            'pages_fetched' => isset($data['pages_fetched']) ? (int) $data['pages_fetched'] : 0,
            'scroll_rounds' => isset($data['scroll_rounds']) ? (int) $data['scroll_rounds'] : 0,
            'stopped_reason' => isset($data['stopped_reason']) ? $data['stopped_reason'] : 'unknown',
            'jsonl' => isset($data['jsonl']) ? $data['jsonl'] : $jsonlFile,
            'total_draws' => $totalDraws,
        ];
    }

    /**
     * @return array{draws: array, pages_fetched: int, stopped_reason: string}
     */
    public function fetchArchiveAll($stolotoGame, $maxPages = 10, $countPerPage = 50)
    {
        $archiveUrl = $this->getArchiveUrl($stolotoGame);
        $delay = 2000;

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        if (isset($config['request_delay_ms'])) {
            $delay = (int) $config['request_delay_ms'];
        }

        $tmpFile = dirname(__DIR__, 2) . '/storage/logs/browser_' . $stolotoGame . '_' . time() . '.json';

        $cmd = sprintf(
            '%s --game=%s --url=%s --pages=%d --count=%d --delay=%d --out=%s 2>>%s',
            escapeshellarg($this->runScript),
            escapeshellarg($stolotoGame),
            escapeshellarg($archiveUrl),
            (int) $maxPages,
            (int) $countPerPage,
            (int) $delay,
            escapeshellarg($tmpFile),
            escapeshellarg($this->logPath)
        );

        $this->log('Browser fetch start: ' . $stolotoGame . ' pages=' . $maxPages);

        exec($cmd, $output, $exitCode);

        if (!is_file($tmpFile)) {
            $this->log('Browser fetch failed: no output file, exit=' . $exitCode . ' ' . implode(' ', $output));
            return ['draws' => [], 'pages_fetched' => 0, 'stopped_reason' => 'browser_error'];
        }

        $raw = file_get_contents($tmpFile);
        @unlink($tmpFile);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->log('Browser fetch failed: invalid JSON');
            return ['draws' => [], 'pages_fetched' => 0, 'stopped_reason' => 'invalid_json'];
        }

        if (!empty($data['error'])) {
            $this->log('Browser error: ' . $data['error']);
        }

        $this->log(sprintf(
            'Browser fetch done: %s pages=%d draws=%d stopped=%s',
            $stolotoGame,
            isset($data['pages_fetched']) ? $data['pages_fetched'] : 0,
            count(isset($data['draws']) ? $data['draws'] : []),
            isset($data['stopped_reason']) ? $data['stopped_reason'] : 'unknown'
        ));

        return [
            'draws' => isset($data['draws']) ? $data['draws'] : [],
            'pages_fetched' => isset($data['pages_fetched']) ? (int) $data['pages_fetched'] : 0,
            'stopped_reason' => isset($data['stopped_reason']) ? $data['stopped_reason'] : 'unknown',
        ];
    }

    public function isAvailable()
    {
        if (!is_file($this->runScript) || !is_executable($this->runScript)) {
            return false;
        }

        $nodeBin = $this->browserDir . '/nodejs/bin/node';
        if (!is_executable($nodeBin)) {
            $nodeBinFile = $this->browserDir . '/.node-bin';
            if (!is_file($nodeBinFile)) {
                return false;
            }
            $nodeBin = trim(file_get_contents($nodeBinFile));
            if (!is_executable($nodeBin)) {
                return false;
            }
        }

        return is_dir($this->browserDir . '/node_modules/playwright');
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
     * @return array<int, array>
     */
    private function readJsonl($path)
    {
        $draws = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $draws;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $draws[] = $row;
            }
        }

        fclose($handle);
        return $draws;
    }

    private function countJsonlLines($path)
    {
        $count = 0;
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        fclose($handle);
        return $count;
    }

    private function log($message)
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logPath, $line, FILE_APPEND);
    }
}
