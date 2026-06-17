#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\FetchService;

$fullBackfill = in_array('--backfill', $argv, true);
$fullArchive = in_array('--full-archive', $argv, true);
$slug = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--lottery=') === 0) {
        $slug = substr($arg, strlen('--lottery='));
    }
}

if ($fullArchive) {
    set_time_limit(0);
    ini_set('memory_limit', '512M');
    $service = new FetchService();
    if ($slug !== null) {
        $result = $service->fetchLotteryFullArchive($slug);
        echo json_encode([$slug => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        $results = $service->fetchAllFullArchive();
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    exit(0);
}

$service = new FetchService();

if ($slug !== null) {
    $result = $service->fetchLottery($slug, $fullBackfill);
    echo json_encode([$slug => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    $results = $service->fetchAll($fullBackfill);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
