<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\CuponesController;
use App\Controllers\EncuestasController;
use App\Controllers\MaestrosController;
use App\Controllers\OfertasController;
use App\Core\Router\Router;
use App\Middleware\JwtAuthMiddleware;

class TuristaRoutes
{
    public static function register(Router $router): void
    {
        // 1. Catálogos Maestros
        $router->get('/categorias', [MaestrosController::class, 'categorias']);
        $router->get('/circuitos', [MaestrosController::class, 'circuitos']);
        $router->get('/modalidades', [MaestrosController::class, 'modalidades']);
        $router->get('/localidades', [MaestrosController::class, 'localidades']);

        // 2. Catálogo Público de Ofertas / Promociones
        $router->get('/ofertas', [OfertasController::class, 'index']);
        $router->get('/ofertas/:id', [OfertasController::class, 'show']);
        $router->get('/promociones', [OfertasController::class, 'index']);
        $router->get('/promociones/:id', [OfertasController::class, 'show']);

        // 3. Cupones del Turista
        $router->post('/cupones', [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
        $router->get('/mis-cupones', [CuponesController::class, 'misCupones'], [JwtAuthMiddleware::class]);
        $router->get('/cupones/:codigo', [CuponesController::class, 'show']);

        // Alias canónicos REST BFF
        $router->post('/turista/cupones', [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
        $router->get('/turista/cupones', [CuponesController::class, 'misCupones'], [JwtAuthMiddleware::class]);
        $router->get('/turista/cupones/:codigo', [CuponesController::class, 'show']);

        // 4. Encuestas del Observatorio Turístico
        $router->post('/encuestas', [EncuestasController::class, 'guardar']);
        $router->post('/turista/encuesta', [EncuestasController::class, 'guardar']);
    }
}
