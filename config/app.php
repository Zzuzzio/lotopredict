<?php

return [
    'base_path' => dirname(__DIR__),
    'db_path' => dirname(__DIR__) . '/storage/lotopredict.sqlite',
    'log_path' => dirname(__DIR__) . '/storage/logs/parser.log',
    'cookie_path' => dirname(__DIR__) . '/storage/logs/stoloto_cookies.txt',
    'backfill_limit' => 500,
    'request_delay_ms' => 2000,
    'archive_max_pages' => 20,
    'full_archive_delay_ms' => 150,
    'full_archive_page_size' => 30,
    'full_archive_parallel' => 20,
    'full_archive_prefer_curl' => false,
    'request_retries' => 3,
    'use_browser_parser' => true,
    'node_bin' => 'node',
    'ml_python_bin' => dirname(__DIR__) . '/ml/venv/bin/python',
];
