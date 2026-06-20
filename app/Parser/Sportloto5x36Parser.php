<?php

namespace App\Parser;

use App\Models\Draw;
use App\Models\Lottery;
use App\Services\FetchService;

/**
 * High-throughput parser for Спортлото «5 из 36» (stoloto game: 5x36plus).
 *
 * 5 main numbers (1–36) + 1 bonus field (1–4). Tuned for a dedicated server:
 * larger page batches, higher curl_multi parallelism, browser parallel backfill.
 */
class Sportloto5x36Parser
{
    const SLUG = 'gosloto-5x36plus';
    const GAME = '5x36plus';

    /** @var DrawNormalizer */
    private $normalizer;

    public function __construct()
    {
        $this->normalizer = new DrawNormalizer();
    }

    /**
     * @return array{saved: int, skipped: int, errors: int, pages: int, fetched: int, stopped_reason: string, source: string, jsonl: string|null, min_draw: int|null, db_total: int, coverage_pct: float|null}
     */
    public function fetchFullArchive($importOnly = false, $curlOnly = false, $maxAttempts = 30)
    {
        $lottery = Lottery::findBySlug(self::SLUG);
        if ($lottery === null) {
            return $this->emptyResult('lottery_not_found');
        }

        $config = $this->getLotteryConfig();
        $this->log(sprintf(
            '5x36 full fetch start (import=%s curl_only=%s parallel=%d page_size=%d browser_parallel=%d)',
            $importOnly ? 'yes' : 'no',
            $curlOnly ? 'yes' : 'no',
            $config['fetch_parallel'],
            $config['fetch_page_size'],
            $config['fetch_browser_parallel']
        ));

        $firstDraw = isset($config['first_draw_number']) ? (int) $config['first_draw_number'] : 1;
        $service = new FetchService();

        // Auto-resume loop: Qrator may rate-limit mid-archive. On 'rate_limited'
        // we cool down and resume (JSONL persists progress) until we reach the
        // first draw, the archive completes, or no further progress is made.
        $result = null;
        $attempt = 0;
        $prevMin = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $result = $service->fetchLotteryFullArchive(self::SLUG, $importOnly, $curlOnly);

            $minDraw = Draw::getMinDrawNumber((int) $lottery['id']);
            $reason = isset($result['stopped_reason']) ? $result['stopped_reason'] : 'unknown';

            $done = in_array($reason, ['reached_first_draw', 'archive_complete', 'no_more', 'archive_limit_reached'], true)
                || ($minDraw !== null && $minDraw <= $firstDraw);

            if ($importOnly || $done) {
                break;
            }

            if ($reason === 'rate_limited') {
                $progressed = $prevMin === null || ($minDraw !== null && $minDraw < $prevMin);
                if (!$progressed) {
                    $this->log("5x36 rate_limited with no progress (min={$minDraw}), stopping at attempt {$attempt}");
                    break;
                }
                $cooldown = 60;
                $this->log("5x36 rate_limited at min={$minDraw}, cooldown {$cooldown}s then resume (attempt {$attempt}/{$maxAttempts})");
                $prevMin = $minDraw;
                sleep($cooldown);
                continue;
            }

            // Unknown/other reason — retry once after short pause, then stop if stuck.
            $progressed = $prevMin === null || ($minDraw !== null && $minDraw < $prevMin);
            if (!$progressed) {
                break;
            }
            $prevMin = $minDraw;
            sleep(15);
        }

        $result['attempts'] = $attempt;

        $dbTotal = Draw::count((int) $lottery['id']);
        $minDraw = Draw::getMinDrawNumber((int) $lottery['id']);
        $maxDraw = Draw::getMaxDrawNumber((int) $lottery['id']);
        $firstDraw = isset($config['first_draw_number']) ? (int) $config['first_draw_number'] : 1;

        $expected = null;
        if ($maxDraw !== null && $maxDraw >= $firstDraw) {
            $expected = $maxDraw - $firstDraw + 1;
        }

        $coverage = ($expected !== null && $expected > 0)
            ? round(min(100, ($dbTotal / $expected) * 100), 2)
            : null;

        $result['db_total'] = $dbTotal;
        $result['min_draw'] = $minDraw;
        $result['max_draw'] = $maxDraw;
        $result['coverage_pct'] = $coverage;

        $this->log(sprintf(
            '5x36 done: saved=%d db_total=%d range=%s..%s coverage=%s%% stopped=%s source=%s',
            $result['saved'],
            $dbTotal,
            $minDraw ?? '-',
            $maxDraw ?? '-',
            $coverage !== null ? (string) $coverage : '-',
            $result['stopped_reason'],
            $result['source']
        ));

        return $result;
    }

    /**
     * Incremental update — latest draws only.
     *
     * @return array
     */
    public function fetchRecent($backfill = false)
    {
        $service = new FetchService();
        return $service->fetchLottery(self::SLUG, $backfill);
    }

    /**
     * Validate raw API draw payload for 5x36plus (5 main + 1 bonus).
     */
    public function validateRawDraw(array $raw)
    {
        $config = $this->getLotteryConfig();
        $options = [
            'bonus_count' => (int) $config['bonus_count'],
            'bonus_max_number' => (int) $config['bonus_max_number'],
        ];

        return $this->normalizer->normalize($raw, (int) $config['numbers_count'], $options);
    }

    /**
     * @return array
     */
    public function status()
    {
        $lottery = Lottery::findBySlug(self::SLUG);
        if ($lottery === null) {
            return ['error' => 'lottery_not_in_db'];
        }

        $lotteryId = (int) $lottery['id'];
        $config = $this->getLotteryConfig();
        $storageDir = dirname(__DIR__, 2) . '/storage/logs';
        $jsonl = $storageDir . '/archive_' . self::GAME . '.jsonl';
        $progress = $storageDir . '/archive_' . self::GAME . '_progress.json';

        $progressData = is_file($progress)
            ? json_decode(file_get_contents($progress), true)
            : null;

        return [
            'slug' => self::SLUG,
            'game' => self::GAME,
            'db_total' => Draw::count($lotteryId),
            'min_draw' => Draw::getMinDrawNumber($lotteryId),
            'max_draw' => Draw::getMaxDrawNumber($lotteryId),
            'first_draw' => isset($config['first_draw_number']) ? (int) $config['first_draw_number'] : 1,
            'jsonl_exists' => is_file($jsonl),
            'jsonl_lines' => is_file($jsonl) ? $this->countJsonlLines($jsonl) : 0,
            'progress' => $progressData,
        ];
    }

    /**
     * @return array
     */
    private function getLotteryConfig()
    {
        $lotteries = require dirname(__DIR__, 2) . '/config/lotteries.php';
        return isset($lotteries[self::SLUG]) ? $lotteries[self::SLUG] : [];
    }

    /**
     * @return array
     */
    private function emptyResult($reason)
    {
        return [
            'saved' => 0,
            'skipped' => 0,
            'errors' => 1,
            'pages' => 0,
            'fetched' => 0,
            'stopped_reason' => $reason,
            'source' => 'none',
            'jsonl' => null,
            'min_draw' => null,
            'db_total' => 0,
            'coverage_pct' => null,
        ];
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
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        file_put_contents(
            $config['log_path'],
            '[' . date('Y-m-d H:i:s') . '] [5x36] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
