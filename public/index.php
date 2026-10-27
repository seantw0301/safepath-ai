<?php

declare(strict_types=1);

use SafePath\Http\Response;
use SafePath\Http\Router;
use SafePath\Infra\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$router = new Router();
$todo = static fn () => Response::json(['error' => 'not_implemented'], 501);

$router->add('GET', '/health', static fn () => Response::json(['ok' => true]));
$router->add('POST', '/api/households', $todo);
$router->add('POST', '/api/households/{id}/scans', $todo);
$router->add('GET', '/api/scans/{id}', $todo);
$router->add('GET', '/api/scans/{id}/report', $todo);
$router->add('GET', '/api/scans/{id}/pack.pdf', $todo);
$router->add('GET', '/api/scans/{id}/work-orders', $todo);
$router->add('POST', '/api/scans/{id}/verify', $todo);
$router->add('PATCH', '/api/hazards/{id}', $todo);
$router->add('POST', '/api/households/{id}/voice', $todo);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
