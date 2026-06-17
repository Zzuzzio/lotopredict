<?php

namespace App\Parser;

class StolotoFullArchiveFetcher
{
    /** @var StolotoClient */
    private $client;

    /** @var StolotoArchiveExporter */
    private $exporter;

    /** @var string */
    private $logPath;

    /** @var int */
    private $delayMs;

    /** @var int */
    private $pageSize;

    /** @var int */
    private $parallel;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->client = new StolotoClient();
        $this->exporter = new StolotoArchiveExporter($this->client);
        $this->logPath = $config['log_path'];
        $this->delayMs = isset($config['full_archive_delay_ms']) ? (int) $config['full_archive_delay_ms'] : 150;
        $this->pageSize = isset($config['full_archive_page_size']) ? (int) $config['full_archive_page_size'] : 100;
        $this->parallel = isset($config['full_archive_parallel']) ? (int) $config['full_archive_parallel'] : 20;
    }

    /**
     * @param int|null $seedMaxDraw known max draw from DB when archive API is blocked
     * @return array{total_draws: int, min_draw: int|null, max_draw: int|null, pages_fetched: int, stopped_reason: string, jsonl: string}
     */
    public function fetch($stolotoGame, $jsonlPath, $progressPath = null, $seedMaxDraw = null)
    {
        $seen = $this->loadSeenFromJsonl($jsonlPath);
        $pagesFetched = 0;
        $stoppedReason = 'complete';
        $storageDir = dirname($jsonlPath);

        $this->log(sprintf(
            'CURL full archive start: %s, already %d draws, parallel=%d, page_size=%d, seed=%s',
            $stolotoGame,
            count($seen),
            $this->parallel,
            $this->pageSize,
            $seedMaxDraw !== null ? (string) $seedMaxDraw : '-'
        ));

        $this->client->clearSession();

        if (count($seen) === 0) {
            $exportFile = $this->exporter->tryDownload($stolotoGame, $storageDir);
            if ($exportFile !== null) {
                $exportDraws = $this->exporter->parseFile($exportFile);
                if ($exportDraws !== []) {
                    $added = $this->appendDraws($jsonlPath, $exportDraws, $seen);
                    $this->log("Official export: +{$added} draws from " . basename($exportFile));
                    $this->writeProgress($progressPath, $stolotoGame, $seen, 0, 'official_export');
                }
            }
        }

        $minBeforePages = $this->minSeen($seen);
        if ($minBeforePages === null || $minBeforePages > 10000) {
            $pageResult = $this->fetchArchivePages($stolotoGame, $jsonlPath, $seen, $progressPath);
            $pagesFetched += $pageResult['pages'];
            if ($pageResult['reason'] !== 'complete') {
                $stoppedReason = $pageResult['reason'];
            }
        }

        $minDraw = $this->minSeen($seen);

        if ($minDraw === null) {
            $retry = $this->client->fetchArchivePageFresh($stolotoGame, 1, min($this->pageSize, 30));
            if ($retry['draws'] !== []) {
                $pagesFetched++;
                $this->appendDraws($jsonlPath, $retry['draws'], $seen);
                $minDraw = $this->minSeen($seen);
            }
        }

        if ($minDraw === null && $seedMaxDraw !== null) {
            $this->log("Archive API blocked, probing single-draw from seed #{$seedMaxDraw}");
            for ($probe = $seedMaxDraw; $probe >= max(1, $seedMaxDraw - 30); $probe--) {
                $draw = $this->client->probeSingleDraw($stolotoGame, $probe);
                if ($draw !== null) {
                    $this->appendDraws($jsonlPath, [$draw], $seen);
                    $minDraw = $this->minSeen($seen);
                    $this->log("Single-draw API works at #{$probe}");
                    break;
                }
            }
        }

        if ($minDraw === null && $seedMaxDraw !== null) {
            $this->log("Trying parallel fetch from seed #{$seedMaxDraw} without archive API");
            $stoppedReason = $this->fetchDrawsParallel(
                $stolotoGame,
                $jsonlPath,
                $seen,
                $progressPath,
                $pagesFetched,
                $seedMaxDraw
            );

            return [
                'total_draws' => count($seen),
                'min_draw' => $this->minSeen($seen),
                'max_draw' => $this->maxSeen($seen),
                'pages_fetched' => $pagesFetched,
                'stopped_reason' => count($seen) > 0 ? $stoppedReason : 'api_blocked',
                'jsonl' => $jsonlPath,
            ];
        }

        if ($minDraw === null) {
            return [
                'total_draws' => 0,
                'min_draw' => null,
                'max_draw' => null,
                'pages_fetched' => $pagesFetched,
                'stopped_reason' => 'api_blocked',
                'jsonl' => $jsonlPath,
            ];
        }

        if ($minDraw > 1) {
            $stoppedReason = $this->fetchDrawsParallel(
                $stolotoGame,
                $jsonlPath,
                $seen,
                $progressPath,
                $pagesFetched,
                $minDraw - 1
            );
        } else {
            $stoppedReason = 'reached_first_draw';
        }

        return [
            'total_draws' => count($seen),
            'min_draw' => $this->minSeen($seen),
            'max_draw' => $this->maxSeen($seen),
            'pages_fetched' => $pagesFetched,
            'stopped_reason' => $stoppedReason,
            'jsonl' => $jsonlPath,
        ];
    }

    /**
     * @param array<int, true> $seen
     * @return array{pages: int, reason: string}
     */
    private function fetchArchivePages($stolotoGame, $jsonlPath, array &$seen, $progressPath)
    {
        $pagesFetched = 0;
        $stoppedReason = 'complete';

        for ($page = 1; $page <= 50000; $page++) {
            $result = $this->client->fetchArchivePageFresh($stolotoGame, $page, $this->pageSize);
            if ($result['draws'] === []) {
                if ($page === 1) {
                    $stoppedReason = 'api_error';
                }
                $this->log("Archive page {$page} blocked after {$pagesFetched} pages");
                break;
            }

            $pagesFetched++;
            $added = $this->appendDraws($jsonlPath, $result['draws'], $seen);
            $this->writeProgress($progressPath, $stolotoGame, $seen, $pagesFetched, 'archive_pages');

            $this->log(sprintf(
                'Archive page %d: +%d total=%d min=%s',
                $page,
                $added,
                count($seen),
                $this->minSeen($seen)
            ));

            if (!$result['has_more'] || count($result['draws']) < $this->pageSize) {
                $stoppedReason = 'archive_complete';
                break;
            }

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
        }

        return ['pages' => $pagesFetched, 'reason' => $stoppedReason];
    }

    /**
     * @param array<int, true> $seen
     */
    private function fetchDrawsParallel($stolotoGame, $jsonlPath, array &$seen, $progressPath, $pagesFetched, $startFrom)
    {
        $this->log("Parallel draw-by-number from {$startFrom} down to 1 (batch={$this->parallel})");

        $stoppedReason = 'complete';
        $emptyBatches = 0;
        $batch = [];

        for ($n = $startFrom; $n >= 1; $n--) {
            if (isset($seen[$n])) {
                continue;
            }

            $batch[] = $n;

            if (count($batch) < $this->parallel && $n > 1) {
                continue;
            }

            $results = $this->client->fetchDrawsByNumbersBatch($stolotoGame, $batch);
            $added = 0;
            $hits = 0;

            foreach ($results as $draw) {
                if ($draw !== null) {
                    $hits++;
                    if ($this->appendDraws($jsonlPath, [$draw], $seen)) {
                        $added++;
                    }
                }
            }

            if ($hits === 0) {
                $emptyBatches++;
                if ($emptyBatches >= 5) {
                    $this->log('5 empty batches in a row, stopping');
                    $stoppedReason = 'too_many_misses';
                    break;
                }
            } else {
                $emptyBatches = 0;
            }

            $lowest = min($batch);
            if ($lowest % 2000 === 0 || $lowest <= 1) {
                $this->writeProgress($progressPath, $stolotoGame, $seen, $pagesFetched, 'draw_parallel', $lowest);
                $this->log(sprintf(
                    'Batch @%d: +%d total=%d min=%s (~%s left)',
                    $lowest,
                    $added,
                    count($seen),
                    $this->minSeen($seen),
                    $this->estimateEta($this->minSeen($seen), $startFrom)
                ));
            }

            $batch = [];

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }

            if ($this->minSeen($seen) !== null && $this->minSeen($seen) <= 1) {
                $stoppedReason = 'reached_first_draw';
                break;
            }
        }

        return $stoppedReason;
    }

    private function estimateEta($minDraw, $startFrom)
    {
        if ($minDraw === null || $minDraw <= 1) {
            return '0m';
        }

        $remaining = $minDraw - 1;
        $batches = (int) ceil($remaining / max(1, $this->parallel));
        $seconds = $batches * ($this->delayMs / 1000 + 0.8);

        if ($seconds < 3600) {
            return round($seconds / 60) . 'm';
        }

        return round($seconds / 3600, 1) . 'h';
    }

    /**
     * @return array<int, true>
     */
    private function loadSeenFromJsonl($jsonlPath)
    {
        $seen = [];
        if (!is_file($jsonlPath)) {
            return $seen;
        }

        $handle = fopen($jsonlPath, 'r');
        if ($handle === false) {
            return $seen;
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && !empty($row['number'])) {
                $seen[(int) $row['number']] = true;
            }
        }

        fclose($handle);
        return $seen;
    }

    /**
     * @param array<int, true> $seen
     */
    private function appendDraws($jsonlPath, array $draws, array &$seen)
    {
        $dir = dirname($jsonlPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $added = 0;
        $handle = fopen($jsonlPath, 'a');
        if ($handle === false) {
            return 0;
        }

        foreach ($draws as $draw) {
            $num = isset($draw['number']) ? (int) $draw['number'] : 0;
            if ($num <= 0 || isset($seen[$num])) {
                continue;
            }
            $seen[$num] = true;
            fwrite($handle, json_encode($draw, JSON_UNESCAPED_UNICODE) . "\n");
            $added++;
        }

        fclose($handle);
        return $added;
    }

    /**
     * @param array<int, true> $seen
     */
    private function writeProgress($path, $game, array $seen, $pages, $phase, $currentDraw = null)
    {
        if (!$path) {
            return;
        }

        $data = [
            'game' => $game,
            'phase' => $phase,
            'total_draws' => count($seen),
            'min_draw' => $this->minSeen($seen),
            'max_draw' => $this->maxSeen($seen),
            'pages_fetched' => $pages,
            'current_draw' => $currentDraw,
            'updated_at' => date('c'),
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array<int, true> $seen
     * @return int|null
     */
    private function minSeen(array $seen)
    {
        return count($seen) > 0 ? min(array_keys($seen)) : null;
    }

    /**
     * @param array<int, true> $seen
     * @return int|null
     */
    private function maxSeen(array $seen)
    {
        return count($seen) > 0 ? max(array_keys($seen)) : null;
    }

    private function log($message)
    {
        file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
