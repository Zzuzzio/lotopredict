<?php

namespace App\Models;

use App\Database\Connection;
use PDO;

class Draw
{
    public static function upsert($lotteryId, $drawNumber, $drawDate, array $numbers)
    {
        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'SELECT id FROM draws WHERE lottery_id = :lottery_id AND draw_number = :draw_number LIMIT 1'
        );
        $stmt->execute([
            'lottery_id' => $lotteryId,
            'draw_number' => $drawNumber,
        ]);
        $existing = $stmt->fetch();

        $payload = [
            'draw_date' => $drawDate,
            'numbers' => json_encode(array_values($numbers), JSON_UNESCAPED_UNICODE),
        ];

        if ($existing) {
            $stmt = $pdo->prepare(
                'UPDATE draws SET draw_date = :draw_date, numbers = :numbers, fetched_at = datetime("now")
                 WHERE id = :id'
            );
            $payload['id'] = $existing['id'];
            return $stmt->execute($payload);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO draws (lottery_id, draw_number, draw_date, numbers, fetched_at)
             VALUES (:lottery_id, :draw_number, :draw_date, :numbers, datetime("now"))'
        );

        return $stmt->execute([
            'lottery_id' => $lotteryId,
            'draw_number' => $drawNumber,
            'draw_date' => $drawDate,
            'numbers' => $payload['numbers'],
        ]);
    }

    public static function getLatest($lotteryId, $limit = 10)
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM draws WHERE lottery_id = :lottery_id
             ORDER BY draw_number DESC LIMIT :limit'
        );
        $stmt->bindValue('lottery_id', $lotteryId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return array_map([self::class, 'format'], $rows);
    }

    public static function getMaxDrawNumber($lotteryId)
    {
        $stmt = Connection::get()->prepare(
            'SELECT MAX(draw_number) AS max_num FROM draws WHERE lottery_id = :lottery_id'
        );
        $stmt->execute(['lottery_id' => $lotteryId]);
        $row = $stmt->fetch();
        return $row && $row['max_num'] !== null ? (int) $row['max_num'] : null;
    }

    public static function getMinDrawNumber($lotteryId)
    {
        $stmt = Connection::get()->prepare(
            'SELECT MIN(draw_number) AS min_num FROM draws WHERE lottery_id = :lottery_id'
        );
        $stmt->execute(['lottery_id' => $lotteryId]);
        $row = $stmt->fetch();
        return $row && $row['min_num'] !== null ? (int) $row['min_num'] : null;
    }

    public static function count($lotteryId)
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) AS cnt FROM draws WHERE lottery_id = :lottery_id'
        );
        $stmt->execute(['lottery_id' => $lotteryId]);
        return (int) $stmt->fetch()['cnt'];
    }

    public static function getForPeriod($lotteryId, $limit = null)
    {
        $sql = 'SELECT * FROM draws WHERE lottery_id = :lottery_id ORDER BY draw_number DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = Connection::get()->prepare($sql);
        $stmt->bindValue('lottery_id', $lotteryId, PDO::PARAM_INT);
        if ($limit !== null) {
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_map([self::class, 'format'], $stmt->fetchAll());
    }

    /**
     * Chronological order (oldest first) for ML training.
     *
     * @return array
     */
    public static function getChronological($lotteryId, $limit = null)
    {
        $sql = 'SELECT * FROM draws WHERE lottery_id = :lottery_id ORDER BY draw_number ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = Connection::get()->prepare($sql);
        $stmt->bindValue('lottery_id', $lotteryId, PDO::PARAM_INT);
        if ($limit !== null) {
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        return array_map([self::class, 'format'], $stmt->fetchAll());
    }

    private static function format(array $row)
    {
        $row['numbers'] = json_decode($row['numbers'], true) ?: [];
        $row['draw_number'] = (int) $row['draw_number'];
        $row['lottery_id'] = (int) $row['lottery_id'];
        return $row;
    }
}
