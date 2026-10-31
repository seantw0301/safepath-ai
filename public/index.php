<?php

declare(strict_types=1);

use SafePath\Http\Api;
use SafePath\Http\Response;
use SafePath\Http\Router;
use SafePath\Http\Web;
use SafePath\Infra\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$router = new Router();

$router->add('GET', '/health', static fn () => Response::json(['ok' => true]));
$router->add('POST', '/api/households', static fn () => Api::createHousehold());
$router->add('POST', '/api/households/{id}/scans', Api::createScan(...));
$router->add('GET', '/api/scans/{id}', Api::status(...));
$router->add('GET', '/api/scans/{id}/report', Api::report(...));
$router->add('GET', '/api/scans/{id}/pack.pdf', Api::packPdf(...));
$router->add('GET', '/api/scans/{id}/work-orders', Api::workOrders(...));
$router->add('POST', '/api/scans/{id}/verify', Api::verify(...));
$router->add('PATCH', '/api/hazards/{id}', Api::overrideHazard(...));
$router->add('POST', '/api/households/{id}/voice', Api::voice(...));

$router->add('GET', '/', static fn () => Web::home());
$router->add('GET', '/new', static fn () => Web::newScan());
$router->add('GET', '/scans/{id}', Web::scan(...));
$router->add('GET', '/media/{id}', Web::media(...));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $e) {
    error_log($e->getMessage());
    Response::json(['error' => 'server_error'], 500);
}
