<?php

declare(strict_types=1);

namespace App\Http;

use ArgumentCountError;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $pattern . '$#';

            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            try {
                return ($route['handler'])($request, $params);
            } catch (ArgumentCountError) {
                return ($route['handler'])($request);
            }
        }

        throw new HttpException(404, 'NOT_FOUND', 'Route not found');
    }
}
