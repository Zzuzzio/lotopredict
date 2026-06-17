<?php

namespace App\Controllers;

use App\Models\Lottery;
use App\Services\StatsService;
use App\Support\LotteryHelper;
use App\View;

class StatsController
{
    public function index(): void
    {
        $lotteries = Lottery::all();
        $selectedSlug = $_GET['lottery'] ?? ($lotteries[0]['slug'] ?? 'gosloto-6x45');
        $period = isset($_GET['period']) ? (int) $_GET['period'] : 100;
        if ($period <= 0) {
            $period = null;
        }

        $lottery = Lottery::findBySlug($selectedSlug) ?? $lotteries[0] ?? null;
        $lotteryConfig = $lottery !== null ? LotteryHelper::merged($lottery['slug']) : [];
        $summary = null;

        if ($lottery !== null) {
            $service = new StatsService();
            $summary = $service->getSummary(
                (int) $lottery['id'],
                (int) $lottery['max_number'],
                $period,
                $lotteryConfig
            );
        }

        View::render('stats', [
            'lotteries' => $lotteries,
            'lottery' => $lottery,
            'lotteryConfig' => $lotteryConfig,
            'period' => $period,
            'summary' => $summary,
        ]);
    }

    public function api(): void
    {
        $slug = $_GET['lottery'] ?? 'gosloto-6x45';
        $period = isset($_GET['period']) ? (int) $_GET['period'] : 100;
        if ($period <= 0) {
            $period = null;
        }

        $lottery = Lottery::findBySlug($slug);
        if ($lottery === null) {
            View::json(['error' => 'Lottery not found'], 404);
            return;
        }

        $lotteryConfig = LotteryHelper::merged($slug);
        $service = new StatsService();
        View::json($service->getSummary(
            (int) $lottery['id'],
            (int) $lottery['max_number'],
            $period,
            $lotteryConfig
        ));
    }
}
