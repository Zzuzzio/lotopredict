<?php

namespace App\Models;

use App\Database\Connection;
use PDO;

class Lottery
{
    public static function all()
    {
        $stmt = Connection::get()->query('SELECT * FROM lotteries ORDER BY id');
        return $stmt->fetchAll();
    }

    public static function findBySlug($slug)
    {
        $stmt = Connection::get()->prepare('SELECT * FROM lotteries WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }

    public static function findById($id)
    {
        $stmt = Connection::get()->prepare('SELECT * FROM lotteries WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }
}
