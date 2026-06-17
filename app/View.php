<?php

namespace App;

class View
{
    public static function render($template, array $data = [])
    {
        $assetBase = self::getAssetBase();
        extract(array_merge(['assetBase' => $assetBase], $data), EXTR_SKIP);
        $templatePath = dirname(__DIR__) . '/app/Views/' . $template . '.php';
        $layoutPath = dirname(__DIR__) . '/app/Views/layout.php';

        ob_start();
        require $templatePath;
        $content = ob_get_clean();

        require $layoutPath;
    }

    public static function json(array $data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private static function getAssetBase()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.') {
            return '';
        }
        if (basename($dir) === 'public') {
            $dir = dirname($dir);
            if ($dir === '/' || $dir === '.') {
                return '';
            }
        }
        $assetBase = rtrim($dir, '/');
        return $assetBase === '/' ? '' : $assetBase;
    }
}
