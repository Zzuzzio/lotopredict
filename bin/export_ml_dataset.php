#!/usr/bin/env php
<?php
/**
 * Export lottery draws for ML training.
 * Usage: php bin/export_ml_dataset.php --lottery=gosloto-5x36plus
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Models\Draw;
use App\Models\Lottery;
use App\Support\LotteryHelper;

$slug = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--lottery=') === 0) {
        $slug = substr($arg, strlen('--lottery='));
    }
}

if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/export_ml_dataset.php --lottery=gosloto-5x36plus\n");
    exit(1);
}

$lottery = Lottery::findBySlug($slug);
if ($lottery === null) {
    fwrite(STDERR, "Unknown lottery: {$slug}\n");
    exit(1);
}

$config = LotteryHelper::fileConfig($slug);
$draws = Draw::getChronological((int) $lottery['id']);
$outDir = dirname(__DIR__) . '/storage/ml';

if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$rows = [];
foreach ($draws as $draw) {
    $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
    if (count($parts['main']) < (int) $config['numbers_count']) {
        continue;
    }
    $row = [
        'draw_number' => (int) $draw['draw_number'],
        'draw_date' => $draw['draw_date'],
        'main' => array_map('intval', $parts['main']),
        'bonus' => array_map('intval', $parts['bonus']),
    ];
    $rows[] = $row;
}

$dataset = [
    'lottery' => $slug,
    'stoloto_game' => $lottery['stoloto_game'],
    'main_count' => (int) $config['numbers_count'],
    'main_max' => (int) $config['max_number'],
    'bonus_count' => (int) (isset($config['bonus_count']) ? $config['bonus_count'] : 0),
    'bonus_max' => (int) (isset($config['bonus_max_number']) ? $config['bonus_max_number'] : 0),
    'exported_at' => date('c'),
    'total' => count($rows),
    'draws' => $rows,
];

$outFile = $outDir . '/' . $slug . '.json';
file_put_contents($outFile, json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'file' => $outFile,
    'total' => count($rows),
    'min_draw' => count($rows) > 0 ? $rows[0]['draw_number'] : null,
    'max_draw' => count($rows) > 0 ? $rows[count($rows) - 1]['draw_number'] : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
