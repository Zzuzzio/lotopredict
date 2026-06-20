#!/usr/bin/env php
<?php
/**
 * Dedicated parser CLI for Спортлото «5 из 36» (5x36plus).
 *
 * Examples:
 *   php bin/fetch_5x36plus.php --status
 *   php bin/fetch_5x36plus.php --recent
 *   php bin/fetch_5x36plus.php --recent --backfill
 *   php bin/fetch_5x36plus.php --full
 *   php bin/fetch_5x36plus.php --full --curl-only
 *   php bin/fetch_5x36plus.php --import-only
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Parser\Sportloto5x36Parser;

set_time_limit(0);
ini_set('memory_limit', '512M');

$parser = new Sportloto5x36Parser();

$showStatus = in_array('--status', $argv, true);
$recent = in_array('--recent', $argv, true);
$full = in_array('--full', $argv, true);
$importOnly = in_array('--import-only', $argv, true);
$curlOnly = in_array('--curl-only', $argv, true);
$backfill = in_array('--backfill', $argv, true);

if ($showStatus) {
    echo json_encode($parser->status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

echo "=== 5 из 36 parser — " . date('Y-m-d H:i:s') . " ===\n";
echo "CPU cores: " . (int) @shell_exec('nproc') . "\n";

if ($full || $importOnly) {
    if ($curlOnly) {
        echo "Mode: full archive (curl-only, parallel=50)\n";
    } elseif ($importOnly) {
        echo "Mode: import JSONL → SQLite\n";
    } else {
        echo "Mode: full archive (browser + parallel backfill)\n";
    }

    $result = $parser->fetchFullArchive($importOnly, $curlOnly);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($result['errors'] > 0 && ($result['db_total'] ?? 0) === 0 ? 1 : 0);
}

// Default: incremental recent fetch
echo "Mode: recent draws" . ($backfill ? ' (backfill)' : '') . "\n";
$result = $parser->fetchRecent($backfill);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['errors'] > 0 && ($result['fetched'] ?? 0) === 0 ? 1 : 0);
