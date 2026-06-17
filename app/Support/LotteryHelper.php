<?php

namespace App\Support;

use App\Models\Lottery;

class LotteryHelper
{
    /**
     * @return array
     */
    public static function fileConfig($slug)
    {
        static $all = null;
        if ($all === null) {
            $all = require dirname(__DIR__, 2) . '/config/lotteries.php';
        }

        return isset($all[$slug]) ? $all[$slug] : [];
    }

    /**
     * @return array
     */
    public static function merged($slug)
    {
        $db = Lottery::findBySlug($slug);
        $file = self::fileConfig($slug);

        return $db !== null ? array_merge($file, $db) : $file;
    }

    /**
     * @param array $config
     */
    public static function hasBonus(array $config)
    {
        return !empty($config['bonus_count']);
    }

    /**
     * @param array $numbers
     * @param array $config
     * @return array{main: int[], bonus: int[]}
     */
    public static function splitNumbers(array $numbers, array $config)
    {
        $bonusCount = (int) (isset($config['bonus_count']) ? $config['bonus_count'] : 0);

        if ($bonusCount === 0) {
            return [
                'main' => array_values($numbers),
                'bonus' => [],
            ];
        }

        $mainCount = (int) $config['numbers_count'];

        return [
            'main' => array_values(array_slice($numbers, 0, $mainCount)),
            'bonus' => array_values(array_slice($numbers, $mainCount, $bonusCount)),
        ];
    }

    /**
     * @param array $config
     */
    public static function formatLabel(array $config)
    {
        $main = (int) $config['numbers_count'];
        $max = (int) $config['max_number'];

        if (self::hasBonus($config)) {
            $bonusMax = (int) $config['bonus_max_number'];
            return $main . '+1 из ' . $max . ' (бонус 1–' . $bonusMax . ')';
        }

        return $main . ' из ' . $max;
    }
}
