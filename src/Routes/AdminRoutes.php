<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AdminController;
use App\Core\Router\Router;
use App\Middleware\JwtAuthMiddleware;

class AdminRoutes
{
    public static function register(Router $router): void
    {
        // 1. Fiscalización de Promociones
        $router->get('/admin/promociones/pendientes', [AdminController::class, 'promocionesPendientes'], [JwtAuthMiddleware::class]);
        $router->get('/admin/aprobaciones', [AdminController::class, 'promocionesPendientes'], [JwtAuthMiddleware::class]);
        $router->post('/admin/promociones/:id/aprobar', [AdminController::class, 'aprobarPromocion'], [JwtAuthMiddleware::class]);
        $router->post('/admin/promociones/:id/rechazar', [AdminController::class, 'rechazarPromocion'], [JwtAuthMiddleware::class]);

        // 2. Prestadores y Métricas Globales
        $router->get('/admin/prestadores', [AdminController::class, 'prestadores'], [JwtAuthMiddleware::class]);
        $router->get('/admin/comercios', [AdminController::class, 'prestadores'], [JwtAuthMiddleware::class]);
        $router->get('/admin/metricas-globales', [AdminController::class, 'metricasGlobales'], [JwtAuthMiddleware::class]);
        $router->get('/admin/resumen', [AdminController::class, 'metricasGlobales'], [JwtAuthMiddleware::class]);
        $router->get('/admin/movimientos', [AdminController::class, 'prestadores'], [JwtAuthMiddleware::class]);
    }
}
