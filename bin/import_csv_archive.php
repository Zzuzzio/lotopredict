#!/usr/bin/env php
<?php
/**
 * Import Stoloto CSV export into database.
 *
 * Usage:
 *   php bin/import_csv_archive.php --lottery=gosloto-5x36plus
 *   php bin/import_csv_archive.php --lottery=gosloto-5x36plus --file=storage/logs/export_5x36plus.csv
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Models\Draw;
use App\Models\Lottery;
use App\Parser\DrawNormalizer;
use App\Parser\StolotoArchiveExporter;

set_time_limit(0);
ini_set('memory_limit', '512M');

$slug = null;
$file = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--lottery=') === 0) {
        $slug = substr($arg, strlen('--lottery='));
    }
    if (strpos($arg, '--file=') === 0) {
        $file = substr($arg, strlen('--file='));
    }
}

if ($slug === null) {
    fwrite(STDERR, "Usage: php bin/import_csv_archive.php --lottery=gosloto-5x36plus [--file=path.csv]\n");
    exit(1);
}

$lottery = Lottery::findBySlug($slug);
if ($lottery === null) {
    fwrite(STDERR, "Unknown lottery: {$slug}\n");
    exit(1);
}

$lotteries = require dirname(__DIR__) . '/config/lotteries.php';
$lotteryConfig = isset($lotteries[$slug]) ? $lotteries[$slug] : [];

if ($file === null) {
    $file = dirname(__DIR__) . '/storage/logs/export_' . $lottery['stoloto_game'] . '.csv';
}

if (!is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

echo "Importing from: {$file}\n";
echo "Lottery: {$lottery['name']} ({$slug})\n";

$exporter = new StolotoArchiveExporter();
$draws = $exporter->parseFile($file);

if ($draws === []) {
    fwrite(STDERR, "No draws parsed from CSV. Check file format.\n");
    exit(1);
}

$normalizer = new DrawNormalizer();
$numbersCount = (int) $lottery['numbers_count'];
$normalizeOptions = [];
if (!empty($lotteryConfig['bonus_count'])) {
    $normalizeOptions['bonus_count'] = (int) $lotteryConfig['bonus_count'];
    if (!empty($lotteryConfig['bonus_max_number'])) {
        $normalizeOptions['bonus_max_number'] = (int) $lotteryConfig['bonus_max_number'];
    }
}

$saved = 0;
$skipped = 0;
$errors = 0;

foreach ($draws as $raw) {
    $normalized = $normalizer->normalize($raw, $numbersCount, $normalizeOptions);
    if ($normalized === null) {
        $errors++;
        continue;
    }

    $ok = Draw::upsert(
        (int) $lottery['id'],
        $normalized['draw_number'],
        $normalized['draw_date'],
        $normalized['numbers']
    );

    if ($ok) {
        $saved++;
    } else {
        $skipped++;
    }
}

$total = Draw::count((int) $lottery['id']);
$minStmt = \App\Database\Connection::get()->prepare(
    'SELECT MIN(draw_number) AS min_draw, MAX(draw_number) AS max_draw FROM draws WHERE lottery_id = ?'
);
$minStmt->execute([(int) $lottery['id']]);
$range = $minStmt->fetch();

$result = [
    'file' => $file,
    'parsed' => count($draws),
    'saved' => $saved,
    'skipped' => $skipped,
    'errors' => $errors,
    'db_total' => $total,
    'db_min_draw' => $range ? (int) $range['min_draw'] : null,
    'db_max_draw' => $range ? (int) $range['max_draw'] : null,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
