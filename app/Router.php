<?php

namespace App;

class Router
{
    private $routes = [];

    public function get($path, callable $handler)
    {
        $this->add('GET', $path, $handler);
    }

    public function post($path, callable $handler)
    {
        $this->add('POST', $path, $handler);
    }

    private function add($method, $path, callable $handler)
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch($method, $uri)
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->normalize($path);

        $basePath = $this->getBasePath();
        if ($basePath !== '' && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath)) ?: '/';
            $path = $this->normalize($path);
        }

        $handler = isset($this->routes[$method][$path]) ? $this->routes[$method][$path] : null;

        if ($handler === null) {
            http_response_code(404);
            echo '404 Not Found';
            return;
        }

        $handler();
    }

    private function normalize($path)
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function getBasePath()
    {
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '.') {
            return '';
        }

        // When front controller is in public/, mount path is the parent directory.
        if (basename($dir) === 'public') {
            $dir = dirname($dir);
            if ($dir === '/' || $dir === '.') {
                return '';
            }
        }

        return rtrim($dir, '/');
    }
}
