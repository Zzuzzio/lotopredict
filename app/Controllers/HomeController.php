<?php

namespace App\Controllers;

use App\Models\Draw;
use App\Models\Lottery;
use App\Support\LotteryHelper;
use App\View;

class HomeController
{
    public function index(): void
    {
        $lotteries = Lottery::all();
        $selectedSlug = $_GET['lottery'] ?? ($lotteries[0]['slug'] ?? 'gosloto-6x45');
        $lottery = Lottery::findBySlug($selectedSlug) ?? $lotteries[0] ?? null;
        $lotteryConfig = $lottery !== null ? LotteryHelper::merged($lottery['slug']) : [];

        $latestDraws = [];
        $drawCount = 0;

        if ($lottery !== null) {
            $latestDraws = Draw::getLatest((int) $lottery['id'], 15);
            $drawCount = Draw::count((int) $lottery['id']);
        }

        View::render('home', [
            'lotteries' => $lotteries,
            'lottery' => $lottery,
            'lotteryConfig' => $lotteryConfig,
            'latestDraws' => $latestDraws,
            'drawCount' => $drawCount,
        ]);
    }
}
