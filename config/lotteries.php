<?php

return [
    'gosloto-6x45' => [
        'slug' => 'gosloto-6x45',
        'name' => 'Гослото 6 из 45',
        'stoloto_game' => '6x45',
        'numbers_count' => 6,
        'max_number' => 45,
        'archive_url' => 'https://www.stoloto.ru/6x45/archive',
        'archive_page_size' => 50,
    ],
    'gosloto-7x49' => [
        'slug' => 'gosloto-7x49',
        'name' => 'Гослото 7 из 49',
        'stoloto_game' => '7x49',
        'numbers_count' => 7,
        'max_number' => 49,
        'archive_url' => 'https://www.stoloto.ru/7x49/archive',
        'archive_page_size' => 50,
    ],
    'gosloto-5x36plus' => [
        'slug' => 'gosloto-5x36plus',
        'name' => '5 из 36',
        'stoloto_game' => '5x36plus',
        'numbers_count' => 5,
        'max_number' => 36,
        'bonus_count' => 1,
        'bonus_max_number' => 4,
        'archive_url' => 'https://www.stoloto.ru/5x36plus/archive',
        'archive_page_size' => 100,
        'first_draw_number' => 1,
        // Server-tuned fetch profile (see config/app.php).
        // Browser profile is throttled to stay under Qrator rate limits while
        // still pulling ~1000 draws/wave; backoff+resume handles any blocks.
        'fetch_parallel' => 50,
        'fetch_page_size' => 100,
        'fetch_delay_ms' => 80,
        'fetch_browser_parallel' => 20,
        'fetch_browser_delay_ms' => 1500,
    ],
];
