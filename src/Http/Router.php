<?php

declare(strict_types=1);

namespace SafePath\Http;

final class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = Url::strip($path);
        foreach ($this->routes as [$m, $regex, $handler]) {
            if ($m === $method && preg_match($regex, $path, $match)) {
                $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler($params);
                return;
            }
        }
        Response::json(['error' => 'not_found'], 404);
    }
}
