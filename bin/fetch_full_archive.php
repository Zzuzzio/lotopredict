#!/usr/bin/env php
<?php
/**
 * Full Stoloto archive fetch (from first draw). Long-running — run via SSH/cron only.
 *
 * Examples:
 *   php bin/fetch_full_archive.php
 *   php bin/fetch_full_archive.php --lottery=gosloto-5x36plus
 *   php bin/fetch_full_archive.php --import-only
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\FetchService;

set_time_limit(0);
ini_set('memory_limit', '512M');

$slug = null;
$importOnly = in_array('--import-only', $argv, true);
$curlOnly = in_array('--curl-only', $argv, true);

foreach ($argv as $arg) {
    if (strpos($arg, '--lottery=') === 0) {
        $slug = substr($arg, strlen('--lottery='));
    }
}

echo "=== Full archive fetch started at " . date('Y-m-d H:i:s') . " ===\n";
if ($importOnly) {
    echo "Mode: import-only (from existing JSONL files)\n";
}
if ($curlOnly) {
    echo "Mode: curl-only (no browser, slower but works on low-resource servers)\n";
}

$service = new FetchService();

if ($slug !== null) {
    $result = $service->fetchLotteryFullArchive($slug, $importOnly, $curlOnly);
    echo json_encode([$slug => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    $results = $service->fetchAllFullArchive($importOnly, $curlOnly);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

echo "=== Finished at " . date('Y-m-d H:i:s') . " ===\n";
