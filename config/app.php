<?php

return [
    'base_path' => dirname(__DIR__),
    'db_path' => dirname(__DIR__) . '/storage/lotopredict.sqlite',
    'log_path' => dirname(__DIR__) . '/storage/logs/parser.log',
    'cookie_path' => dirname(__DIR__) . '/storage/logs/stoloto_cookies.txt',
    'backfill_limit' => 500,
    'request_delay_ms' => 1500,
    'archive_max_pages' => 20,
    // Powerful server defaults (4+ cores, 8GB+ RAM)
    'full_archive_delay_ms' => 80,
    'full_archive_page_size' => 100,
    'full_archive_parallel' => 50,
    'full_archive_browser_parallel' => 40,
    'full_archive_browser_delay_ms' => 300,
    'full_archive_prefer_curl' => false,
    'request_retries' => 3,
    'use_browser_parser' => true,
    'node_bin' => 'node',
    'ml_python_bin' => dirname(__DIR__) . '/ml/venv/bin/python',
];
