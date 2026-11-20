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
        // 1. Catálogos Maestros (requiere autenticación)
        $router->get('/categorias', [MaestrosController::class, 'categorias'], [JwtAuthMiddleware::class]);
        $router->get('/circuitos', [MaestrosController::class, 'circuitos'], [JwtAuthMiddleware::class]);
        $router->get('/modalidades', [MaestrosController::class, 'modalidades'], [JwtAuthMiddleware::class]);
        $router->get('/localidades', [MaestrosController::class, 'localidades'], [JwtAuthMiddleware::class]);

        // 2. Catálogo de Ofertas / Promociones (requiere autenticación)
        $router->get('/ofertas', [OfertasController::class, 'index'], [JwtAuthMiddleware::class]);
        $router->get('/ofertas/:id', [OfertasController::class, 'show'], [JwtAuthMiddleware::class]);
        $router->get('/promociones', [OfertasController::class, 'index'], [JwtAuthMiddleware::class]);
        $router->get('/promociones/:id', [OfertasController::class, 'show'], [JwtAuthMiddleware::class]);

        // 3. Cupones del Turista
        $router->post('/cupones', [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
        $router->post('/promociones/:id/canjear', [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
        $router->get('/mis-cupones', [CuponesController::class, 'misCupones'], [JwtAuthMiddleware::class]);
        $router->get('/cupones/:codigo', [CuponesController::class, 'show'], [JwtAuthMiddleware::class]);

        // Alias canónicos REST BFF
        $router->post('/turista/cupones', [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
        $router->get('/turista/cupones', [CuponesController::class, 'misCupones'], [JwtAuthMiddleware::class]);
        $router->get('/turista/cupones/:codigo', [CuponesController::class, 'show'], [JwtAuthMiddleware::class]);

        // 4. Encuestas del Observatorio Turístico
        $router->post('/encuestas', [EncuestasController::class, 'guardar'], [JwtAuthMiddleware::class]);
        $router->post('/turista/encuesta', [EncuestasController::class, 'guardar'], [JwtAuthMiddleware::class]);
    }
}
