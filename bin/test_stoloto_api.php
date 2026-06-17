#!/usr/bin/env php
<?php
/**
 * Quick API connectivity test for Stoloto.
 * Usage: php bin/test_stoloto_api.php 5x36plus
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Models\Draw;
use App\Models\Lottery;
use App\Parser\StolotoClient;

$game = $argv[1] ?? '5x36plus';
$lottery = null;

foreach (Lottery::all() as $row) {
    if ($row['stoloto_game'] === $game) {
        $lottery = $row;
        break;
    }
}

$client = new StolotoClient();
$client->clearSession();

echo "=== Stoloto API test: {$game} ===\n\n";

$page = $client->fetchArchivePageFresh($game, 1, 30);
echo 'Archive page 1: ' . count($page['draws']) . " draws, hasMore=" . ($page['has_more'] ? 'yes' : 'no') . "\n";

$seed = $lottery ? Draw::getMaxDrawNumber((int) $lottery['id']) : null;
$probeNum = $seed ?: 161100;

$single = $client->probeSingleDraw($game, $probeNum);
echo "Single draw #{$probeNum}: " . ($single ? 'OK (number=' . $single['number'] . ')' : 'FAILED') . "\n";

if ($lottery) {
    echo 'DB max draw: ' . ($seed ?: 'none') . "\n";
}

echo "\nIf both FAILED with 403 — use browser mode or upload CSV manually.\n";
