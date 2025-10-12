<?php
declare(strict_types=1);

namespace App\Utils;

class Router {
    private array $routes = [];

    public function get(string $path, callable $handler) {
        $this->routes['GET'][$path] = $handler;
    }
    public function post(string $path, callable $handler) {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri) {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = rtrim($path, '/');
        if ($path === '') $path = '/';
        $handler = $this->routes[$method][$path] ?? null;
        if (!$handler) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not Found', 'path' => $path]);
            return;
        }
        return $handler();
    }
}
