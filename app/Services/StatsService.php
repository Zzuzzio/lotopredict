<?php

namespace App\Services;

use App\Models\Draw;
use App\Support\LotteryHelper;

class StatsService
{
    public function getFrequency($lotteryId, $maxNumber, $period = null, array $config = [])
    {
        $draws = Draw::getForPeriod($lotteryId, $period);
        $frequency = array_fill(1, $maxNumber, 0);
        $mainCount = (int) (isset($config['numbers_count']) ? $config['numbers_count'] : 0);

        foreach ($draws as $draw) {
            $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
            $nums = $mainCount > 0 ? $parts['main'] : $draw['numbers'];

            foreach ($nums as $number) {
                if ($number >= 1 && $number <= $maxNumber) {
                    $frequency[$number]++;
                }
            }
        }

        return $frequency;
    }

    public function getBonusFrequency($lotteryId, $bonusMaxNumber, $period = null, array $config = [])
    {
        if (!LotteryHelper::hasBonus($config)) {
            return [];
        }

        $draws = Draw::getForPeriod($lotteryId, $period);
        $frequency = array_fill(1, $bonusMaxNumber, 0);

        foreach ($draws as $draw) {
            $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
            foreach ($parts['bonus'] as $number) {
                if ($number >= 1 && $number <= $bonusMaxNumber) {
                    $frequency[$number]++;
                }
            }
        }

        return $frequency;
    }

    public function getHotCold($lotteryId, $maxNumber, $topN = 10, $period = null, array $config = [])
    {
        $frequency = $this->getFrequency($lotteryId, $maxNumber, $period, $config);
        arsort($frequency);

        $hot = array_slice(array_keys($frequency), 0, $topN);
        asort($frequency);
        $cold = array_slice(array_keys($frequency), 0, $topN);

        return ['hot' => $hot, 'cold' => $cold];
    }

    public function getBonusHotCold($lotteryId, $bonusMaxNumber, $topN = 4, $period = null, array $config = [])
    {
        $frequency = $this->getBonusFrequency($lotteryId, $bonusMaxNumber, $period, $config);
        if ($frequency === []) {
            return ['hot' => [], 'cold' => []];
        }

        arsort($frequency);
        $hot = array_slice(array_keys($frequency), 0, min($topN, count($frequency)));
        asort($frequency);
        $cold = array_slice(array_keys($frequency), 0, min($topN, count($frequency)));

        return ['hot' => $hot, 'cold' => $cold];
    }

    public function getTopPairs($lotteryId, $limit = 15, $period = null, array $config = [])
    {
        $draws = Draw::getForPeriod($lotteryId, $period);
        $pairs = [];
        $mainCount = (int) (isset($config['numbers_count']) ? $config['numbers_count'] : 0);

        foreach ($draws as $draw) {
            $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
            $numbers = $mainCount > 0 ? $parts['main'] : $draw['numbers'];
            $count = count($numbers);

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = min($numbers[$i], $numbers[$j]);
                    $b = max($numbers[$i], $numbers[$j]);
                    $key = $a . '-' . $b;
                    $pairs[$key] = isset($pairs[$key]) ? $pairs[$key] + 1 : 1;
                }
            }
        }

        arsort($pairs);
        $result = [];
        foreach (array_slice($pairs, 0, $limit, true) as $key => $count) {
            $parts = explode('-', $key);
            $result[] = ['pair' => [(int) $parts[0], (int) $parts[1]], 'count' => $count];
        }

        return $result;
    }

    public function getOverdue($lotteryId, $maxNumber, $topN = 10, array $config = [])
    {
        $draws = Draw::getForPeriod($lotteryId, null);
        $lastSeen = array_fill(1, $maxNumber, PHP_INT_MAX);
        $mainCount = (int) (isset($config['numbers_count']) ? $config['numbers_count'] : 0);

        foreach ($draws as $index => $draw) {
            $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
            $numbers = $mainCount > 0 ? $parts['main'] : $draw['numbers'];

            foreach ($numbers as $number) {
                if ($number >= 1 && $number <= $maxNumber && $lastSeen[$number] === PHP_INT_MAX) {
                    $lastSeen[$number] = $index;
                }
            }
        }

        $overdue = [];
        foreach ($lastSeen as $number => $since) {
            $overdue[] = [
                'number' => $number,
                'draws_since' => $since === PHP_INT_MAX ? count($draws) : $since,
            ];
        }

        usort($overdue, function ($a, $b) {
            if ($a['draws_since'] === $b['draws_since']) {
                return 0;
            }
            return ($a['draws_since'] < $b['draws_since']) ? 1 : -1;
        });

        return array_slice($overdue, 0, $topN);
    }

    public function getBonusOverdue($lotteryId, $bonusMaxNumber, $topN = 4, array $config = [])
    {
        if (!LotteryHelper::hasBonus($config)) {
            return [];
        }

        $draws = Draw::getForPeriod($lotteryId, null);
        $lastSeen = array_fill(1, $bonusMaxNumber, PHP_INT_MAX);

        foreach ($draws as $index => $draw) {
            $parts = LotteryHelper::splitNumbers($draw['numbers'], $config);
            foreach ($parts['bonus'] as $number) {
                if ($number >= 1 && $number <= $bonusMaxNumber && $lastSeen[$number] === PHP_INT_MAX) {
                    $lastSeen[$number] = $index;
                }
            }
        }

        $overdue = [];
        foreach ($lastSeen as $number => $since) {
            $overdue[] = [
                'number' => $number,
                'draws_since' => $since === PHP_INT_MAX ? count($draws) : $since,
            ];
        }

        usort($overdue, function ($a, $b) {
            if ($a['draws_since'] === $b['draws_since']) {
                return 0;
            }
            return ($a['draws_since'] < $b['draws_since']) ? 1 : -1;
        });

        return array_slice($overdue, 0, $topN);
    }

    public function getSummary($lotteryId, $maxNumber, $period = null, array $config = [])
    {
        $summary = [
            'frequency' => $this->getFrequency($lotteryId, $maxNumber, $period, $config),
            'hot_cold' => $this->getHotCold($lotteryId, $maxNumber, 10, $period, $config),
            'pairs' => $this->getTopPairs($lotteryId, 15, $period, $config),
            'overdue' => $this->getOverdue($lotteryId, $maxNumber, 10, $config),
            'total_draws' => count(Draw::getForPeriod($lotteryId, $period)),
        ];

        if (LotteryHelper::hasBonus($config)) {
            $bonusMax = (int) $config['bonus_max_number'];
            $summary['bonus'] = [
                'frequency' => $this->getBonusFrequency($lotteryId, $bonusMax, $period, $config),
                'hot_cold' => $this->getBonusHotCold($lotteryId, $bonusMax, 4, $period, $config),
                'overdue' => $this->getBonusOverdue($lotteryId, $bonusMax, 4, $config),
            ];
        }

        return $summary;
    }
}
