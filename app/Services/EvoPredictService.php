<?php

namespace App\Services;

class EvoPredictService
{
    private $basePath;
    private $pythonBin;
    private $modelsDir;
    private $logPath;

    public function __construct()
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $this->basePath = $config['base_path'];
        $this->pythonBin = $this->resolvePythonBin($config);
        $this->modelsDir = $this->basePath . '/ml/models';
        $this->logPath = $config['log_path'];
    }

    private function resolvePythonBin(array $config)
    {
        $basePath = $config['base_path'];
        $binFile = $basePath . '/ml/.python-bin';
        if (is_file($binFile)) {
            $bin = trim(file_get_contents($binFile));
            if ($bin !== '' && is_executable($bin)) {
                return $bin;
            }
        }

        if (isset($config['ml_python_bin']) && is_executable($config['ml_python_bin'])) {
            return $config['ml_python_bin'];
        }

        return 'python3';
    }

    /**
     * @param array $lotteryConfig
     * @return array{available: bool, model_path: string|null, metrics: array|null}
     */
    public function getModelInfo($slug)
    {
        $path = $this->modelsDir . '/' . $slug . '.json';
        if (!is_file($path)) {
            return ['available' => false, 'model_path' => null, 'metrics' => null];
        }

        $raw = json_decode(file_get_contents($path), true);
        return [
            'available' => true,
            'model_path' => $path,
            'metrics' => isset($raw['metrics']) ? $raw['metrics'] : null,
            'trained_at' => isset($raw['trained_at']) ? $raw['trained_at'] : null,
        ];
    }

    public function isAvailable($slug)
    {
        $binFile = $this->basePath . '/ml/.python-bin';
        $pythonOk = is_file($binFile) || is_executable($this->pythonBin);
        return $pythonOk && $this->getModelInfo($slug)['available'];
    }

    /**
     * @param int $lotteryId
     * @param array $lotteryConfig
     * @return array<int, array{main: int[], bonus: int[]}>
     */
    public function generate($lotteryId, $slug, array $lotteryConfig, $combinations = 1)
    {
        if (!$this->isAvailable($slug)) {
            return [];
        }

        $modelPath = $this->modelsDir . '/' . $slug . '.json';
        $model = json_decode(file_get_contents($modelPath), true);
        $window = isset($model['window']) ? (int) $model['window'] : 10;

        $draws = \App\Models\Draw::getChronological($lotteryId);
        if (count($draws) < $window) {
            $this->log('EvoPredict: not enough draws (' . count($draws) . ' < ' . $window . ')');
            return [];
        }

        $payloadDraws = [];
        $slice = array_slice($draws, -$window);
        foreach ($slice as $draw) {
            $parts = \App\Support\LotteryHelper::splitNumbers($draw['numbers'], $lotteryConfig);
            $payloadDraws[] = [
                'draw_number' => (int) $draw['draw_number'],
                'main' => $parts['main'],
                'bonus' => $parts['bonus'],
            ];
        }

        $tmpIn = $this->basePath . '/storage/ml/predict_input_' . $slug . '_' . time() . '.json';
        $storageMl = dirname($tmpIn);
        if (!is_dir($storageMl)) {
            mkdir($storageMl, 0755, true);
        }
        file_put_contents($tmpIn, json_encode(['draws' => $payloadDraws], JSON_UNESCAPED_UNICODE));

        $predictScript = $this->basePath . '/ml/predict.py';
        $cmd = sprintf(
            '%s %s --model=%s --input=%s --combinations=%d 2>>%s',
            escapeshellarg($this->pythonBin),
            escapeshellarg($predictScript),
            escapeshellarg($modelPath),
            escapeshellarg($tmpIn),
            max(1, min(5, (int) $combinations)),
            escapeshellarg($this->logPath)
        );

        $output = shell_exec($cmd);
        @unlink($tmpIn);

        if ($output === null || trim($output) === '') {
            $this->log('EvoPredict: empty python output');
            return [];
        }

        $data = json_decode(trim($output), true);
        if (!is_array($data) || empty($data['predictions'])) {
            $this->log('EvoPredict: invalid output: ' . substr($output, 0, 200));
            return [];
        }

        return $data['predictions'];
    }

    private function log($message)
    {
        file_put_contents($this->logPath, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
