<?php
declare(strict_types=1);

namespace App\Http;

/** Dispatches registered routes and distinguishes missing routes from unsupported methods. */
final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[$path]['POST'] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if (!isset($this->routes[$path])) {
            http_response_code(404);
            echo 'Pagina niet gevonden.';
            return;
        }
        if (!isset($this->routes[$path][$method === 'HEAD' ? 'GET' : $method])) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_merge(array_keys($this->routes[$path]), ['HEAD'])));
            echo 'Deze methode is niet toegestaan.';
            return;
        }
        ($this->routes[$path][$method === 'HEAD' ? 'GET' : $method])();
    }
}
