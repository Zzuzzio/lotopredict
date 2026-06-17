<?php

namespace App\Controllers;

use App\Models\Lottery;
use App\Services\EvoPredictService;
use App\Services\PredictService;
use App\Support\LotteryHelper;
use App\View;

class PredictController
{
    public function index(): void
    {
        $lotteries = Lottery::all();
        $selectedSlug = $_POST['lottery'] ?? $_GET['lottery'] ?? ($lotteries[0]['slug'] ?? 'gosloto-6x45');
        $algorithm = $_POST['algorithm'] ?? $_GET['algorithm'] ?? 'frequency';
        $combinations = isset($_POST['combinations']) ? (int) $_POST['combinations'] : 1;
        $period = isset($_POST['period']) ? (int) $_POST['period'] : 100;

        $lottery = Lottery::findBySlug($selectedSlug) ?? $lotteries[0] ?? null;
        $lotteryConfig = $lottery !== null ? LotteryHelper::merged($lottery['slug']) : [];
        $predictions = [];
        $evoModel = null;
        $evoError = null;

        $evoService = new EvoPredictService();
        if ($lottery !== null) {
            $evoModel = $evoService->getModelInfo($lottery['slug']);
        }

        if ($lottery !== null && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['generate']))) {
            if ($algorithm === 'evolution') {
                if ($evoService->isAvailable($lottery['slug'])) {
                    $predictions = $evoService->generate(
                        (int) $lottery['id'],
                        $lottery['slug'],
                        $lotteryConfig,
                        $combinations
                    );
                } else {
                    $evoError = 'Модель не обучена. Запустите: bash bin/train_evolution.sh ' . $lottery['slug'];
                }
            } else {
                $service = new PredictService();
                $predictions = $service->generate(
                    (int) $lottery['id'],
                    $lotteryConfig,
                    $algorithm,
                    $combinations,
                    $period > 0 ? $period : null
                );
            }
        }

        View::render('predict', [
            'lotteries' => $lotteries,
            'lottery' => $lottery,
            'lotteryConfig' => $lotteryConfig,
            'algorithm' => $algorithm,
            'combinations' => $combinations,
            'period' => $period,
            'predictions' => $predictions,
            'evoModel' => $evoModel,
            'evoError' => $evoError,
        ]);
    }
}
