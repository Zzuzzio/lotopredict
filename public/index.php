<?php

require dirname(__DIR__) . '/bootstrap.php';

use App\Controllers\HomeController;
use App\Controllers\PredictController;
use App\Controllers\StatsController;
use App\Router;

$assetBase = '';
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
$dir = str_replace('\\', '/', dirname($scriptName));
if ($dir !== '/' && $dir !== '.') {
    if (basename($dir) === 'public') {
        $dir = dirname($dir);
    }
    if ($dir !== '/' && $dir !== '.') {
        $assetBase = rtrim($dir, '/');
        if ($assetBase === '/') {
            $assetBase = '';
        }
    }
}

$router = new Router();

$router->get('/', function () {
    (new HomeController())->index();
});
$router->get('/stats', function () {
    (new StatsController())->index();
});
$router->get('/api/stats', function () {
    (new StatsController())->api();
});
$router->get('/predict', function () {
    (new PredictController())->index();
});
$router->post('/predict', function () {
    (new PredictController())->index();
});

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

$router->dispatch($method, $uri);
