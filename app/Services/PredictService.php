<?php

namespace App\Services;

use App\Support\LotteryHelper;

class PredictService
{
    /** @var StatsService */
    private $stats;

    public function __construct()
    {
        $this->stats = new StatsService();
    }

    /**
     * @param array $config lottery config (numbers_count, max_number, bonus_count, bonus_max_number)
     * @return array<int, array{main: int[], bonus: int[]}>
     */
    public function generate(
        $lotteryId,
        array $config,
        $algorithm,
        $combinations = 1,
        $period = 100
    ) {
        $combinations = max(1, min(5, $combinations));
        $mainCount = (int) $config['numbers_count'];
        $maxNumber = (int) $config['max_number'];
        $hasBonus = LotteryHelper::hasBonus($config);
        $bonusMax = $hasBonus ? (int) $config['bonus_max_number'] : 0;

        $result = [];

        for ($i = 0; $i < $combinations; $i++) {
            switch ($algorithm) {
                case 'hot':
                    $main = $this->hotNumbers($lotteryId, $mainCount, $maxNumber, $period, $config);
                    break;
                case 'overdue':
                    $main = $this->overdueMix($lotteryId, $mainCount, $maxNumber, $period, $config);
                    break;
                default:
                    $main = $this->frequencyWeighted($lotteryId, $mainCount, $maxNumber, $period, $config);
                    break;
            }

            $bonus = [];
            if ($hasBonus) {
                $bonus = $this->predictBonus($lotteryId, $bonusMax, $period, $config, $algorithm);
            }

            $result[] = ['main' => $main, 'bonus' => $bonus];
        }

        return $result;
    }

    private function frequencyWeighted($lotteryId, $numbersCount, $maxNumber, $period, array $config)
    {
        $frequency = $this->stats->getFrequency($lotteryId, $maxNumber, $period, $config);
        $weights = [];
        $total = array_sum($frequency) ?: 1;

        for ($n = 1; $n <= $maxNumber; $n++) {
            $weights[$n] = ($frequency[$n] + 1) / ($total + $maxNumber);
        }

        return $this->weightedPick($weights, $numbersCount);
    }

    private function hotNumbers($lotteryId, $numbersCount, $maxNumber, $period, array $config)
    {
        $hotCold = $this->stats->getHotCold($lotteryId, $maxNumber, $numbersCount + 2, $period, $config);
        $hot = array_slice($hotCold['hot'], 0, max(1, $numbersCount - 2));
        $cold = array_slice($hotCold['cold'], 0, 2);

        $picked = array_unique(array_merge($hot, $cold));
        $picked = array_slice($picked, 0, $numbersCount);

        if (count($picked) < $numbersCount) {
            $remaining = array_diff(range(1, $maxNumber), $picked);
            shuffle($remaining);
            $picked = array_merge($picked, array_slice($remaining, 0, $numbersCount - count($picked)));
        }

        sort($picked);
        return array_values($picked);
    }

    private function overdueMix($lotteryId, $numbersCount, $maxNumber, $period, array $config)
    {
        $overdue = $this->stats->getOverdue($lotteryId, $maxNumber, (int) ceil($numbersCount / 2), $config);
        $overdueNums = array_column($overdue, 'number');

        $frequency = $this->stats->getFrequency($lotteryId, $maxNumber, $period, $config);
        asort($frequency);
        $midKeys = array_keys($frequency);
        $midStart = (int) floor(count($midKeys) / 3);
        $midNums = array_slice($midKeys, $midStart, $numbersCount);

        $picked = array_unique(array_merge($overdueNums, $midNums));
        $picked = array_slice($picked, 0, $numbersCount);

        if (count($picked) < $numbersCount) {
            $remaining = array_diff(range(1, $maxNumber), $picked);
            shuffle($remaining);
            $picked = array_merge($picked, array_slice($remaining, 0, $numbersCount - count($picked)));
        }

        sort($picked);
        return array_values($picked);
    }

    private function predictBonus($lotteryId, $bonusMaxNumber, $period, array $config, $algorithm)
    {
        if ($algorithm === 'hot') {
            $hotCold = $this->stats->getBonusHotCold($lotteryId, $bonusMaxNumber, 2, $period, $config);
            $pick = !empty($hotCold['hot']) ? (int) $hotCold['hot'][0] : 1;
            return [$pick];
        }

        if ($algorithm === 'overdue') {
            $overdue = $this->stats->getBonusOverdue($lotteryId, $bonusMaxNumber, 2, $config);
            $pick = !empty($overdue) ? (int) $overdue[0]['number'] : 1;
            return [$pick];
        }

        $frequency = $this->stats->getBonusFrequency($lotteryId, $bonusMaxNumber, $period, $config);
        $weights = [];
        $total = array_sum($frequency) ?: 1;

        for ($n = 1; $n <= $bonusMaxNumber; $n++) {
            $weights[$n] = ($frequency[$n] + 1) / ($total + $bonusMaxNumber);
        }

        return $this->weightedPick($weights, 1);
    }

    private function weightedPick(array $weights, $count)
    {
        $picked = [];

        while (count($picked) < $count) {
            $rand = mt_rand() / mt_getrandmax();
            $cumulative = 0.0;
            $added = false;

            foreach ($weights as $number => $weight) {
                if (in_array($number, $picked, true)) {
                    continue;
                }
                $cumulative += $weight;
                if ($rand <= $cumulative) {
                    $picked[] = $number;
                    $added = true;
                    break;
                }
            }

            if (!$added) {
                $remaining = array_diff(array_keys($weights), $picked);
                if ($remaining !== []) {
                    $keys = array_values($remaining);
                    $picked[] = (int) $keys[array_rand($keys)];
                }
            }
        }

        sort($picked);
        return $picked;
    }
}
