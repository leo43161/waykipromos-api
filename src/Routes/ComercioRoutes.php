<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\CanjesController;
use App\Controllers\ComercioPromocionesController;
use App\Core\Router\Router;
use App\Middleware\JwtAuthMiddleware;

class ComercioRoutes
{
    public static function register(Router $router): void
    {
        // 1. Validación y Canje en Mostrador
        $router->post('/canjes/validar', [CanjesController::class, 'validar'], [JwtAuthMiddleware::class]);
        $router->post('/canjes/confirmar', [CanjesController::class, 'confirmar'], [JwtAuthMiddleware::class]);
        $router->post('/canjes/verificar', [CanjesController::class, 'validar'], [JwtAuthMiddleware::class]);

        // 2. Gestión Comercial de Promociones
        $router->get('/comercio/promociones', [ComercioPromocionesController::class, 'index'], [JwtAuthMiddleware::class]);
        $router->post('/comercio/promociones', [ComercioPromocionesController::class, 'create'], [JwtAuthMiddleware::class]);
        $router->put('/comercio/promociones/:id', [ComercioPromocionesController::class, 'update'], [JwtAuthMiddleware::class]);

        // 3. Historial de Canjes y Métricas del Comercio
        $router->get('/comercio/historial-canjes', [ComercioPromocionesController::class, 'historialCanjes'], [JwtAuthMiddleware::class]);
        $router->get('/comercio/movimientos', [ComercioPromocionesController::class, 'historialCanjes'], [JwtAuthMiddleware::class]);
        $router->get('/comercio/metricas', [ComercioPromocionesController::class, 'metricas'], [JwtAuthMiddleware::class]);
        $router->get('/comercio/resumen', [ComercioPromocionesController::class, 'metricas'], [JwtAuthMiddleware::class]);
    }
}
