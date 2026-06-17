<?php

namespace App\Database;

use PDO;

class Connection
{
    /** @var PDO|null */
    private static $instance = null;

    public static function get()
    {
        if (self::$instance === null) {
            $config = require dirname(__DIR__, 2) . '/config/app.php';
            $dbPath = $config['db_path'];
            $dir = dirname($dbPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            self::initialize();
        }

        return self::$instance;
    }

    private static function initialize()
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        self::$instance->exec($schema);

        $lotteries = require dirname(__DIR__, 2) . '/config/lotteries.php';
        $stmt = self::$instance->prepare(
            'INSERT OR IGNORE INTO lotteries (slug, name, numbers_count, max_number, stoloto_game)
             VALUES (:slug, :name, :numbers_count, :max_number, :stoloto_game)'
        );

        foreach ($lotteries as $lottery) {
            $stmt->execute([
                'slug' => $lottery['slug'],
                'name' => $lottery['name'],
                'numbers_count' => $lottery['numbers_count'],
                'max_number' => $lottery['max_number'],
                'stoloto_game' => $lottery['stoloto_game'],
            ]);
        }
    }
}
