<?php

namespace App\Parser;

class DrawNormalizer
{
    /**
     * @param array $raw
     * @param int $mainCount main field size (e.g. 5 for 5x36plus)
     * @param array $options bonus_count, bonus_max_number
     * @return array|null
     */
    public function normalize(array $raw, $mainCount, array $options = [])
    {
        $drawNumber = $this->extractDrawNumber($raw);
        $drawDate = $this->extractDrawDate($raw);
        $numbers = $this->extractNumbers($raw);
        $bonusCount = isset($options['bonus_count']) ? (int) $options['bonus_count'] : 0;
        $totalNeeded = $mainCount + $bonusCount;

        if ($drawNumber === null || $drawDate === null || count($numbers) < $totalNeeded) {
            return null;
        }

        if ($bonusCount > 0) {
            $bonus = array_slice($numbers, -$bonusCount);
            $main = array_slice($numbers, 0, $mainCount);
            sort($main);
            $numbers = array_merge($main, $bonus);

            if (isset($options['bonus_max_number'])) {
                foreach ($bonus as $b) {
                    if ($b < 1 || $b > (int) $options['bonus_max_number']) {
                        return null;
                    }
                }
            }
        } else {
            $numbers = array_slice(array_values(array_unique($numbers)), 0, $mainCount);
            sort($numbers);
        }

        if (count($numbers) < $totalNeeded) {
            return null;
        }

        return [
            'draw_number' => $drawNumber,
            'draw_date' => $drawDate,
            'numbers' => $numbers,
        ];
    }

    private function extractDrawNumber(array $raw)
    {
        foreach (['number', 'drawNumber', 'draw_num'] as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (int) $raw[$key];
            }
        }
        return null;
    }

    private function extractDrawDate(array $raw)
    {
        foreach (['date', 'drawDate', 'draw_date', 'gameDate'] as $key) {
            if (!empty($raw[$key])) {
                $parsed = $this->parseDateString((string) $raw[$key]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
        return null;
    }

    private function parseDateString($value)
    {
        $value = trim($value);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
            $time = sprintf(
                '%s:%s:%s',
                isset($m[4]) ? $m[4] : '00',
                isset($m[5]) ? $m[5] : '00',
                isset($m[6]) ? $m[6] : '00'
            );
            return sprintf('%s-%s-%s %s', $m[3], $m[2], $m[1], $time);
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return null;
    }

    private function extractNumbers(array $raw)
    {
        $candidates = [];

        if (isset($raw['combination']['structured']) && is_array($raw['combination']['structured'])) {
            $candidates = $raw['combination']['structured'];
        } elseif (isset($raw['winningCombination']['structured']) && is_array($raw['winningCombination']['structured'])) {
            $candidates = $raw['winningCombination']['structured'];
        } elseif (isset($raw['winningCombination']) && is_array($raw['winningCombination'])) {
            $candidates = $raw['winningCombination'];
        } elseif (isset($raw['combination']) && is_array($raw['combination'])) {
            $candidates = $raw['combination'];
        } elseif (isset($raw['numbers']) && is_array($raw['numbers'])) {
            $candidates = $raw['numbers'];
        } elseif (isset($raw['winNumbers']) && is_array($raw['winNumbers'])) {
            $candidates = $raw['winNumbers'];
        } elseif (isset($raw['combination']['serialized'])) {
            $candidates = preg_split('/\s+/', trim((string) $raw['combination']['serialized']));
        }

        $numbers = [];
        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                $numbers[] = (int) $value;
            }
        }

        return $numbers;
    }
}
