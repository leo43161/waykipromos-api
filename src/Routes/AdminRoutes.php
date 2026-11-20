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

        // 3. Usuarios y Roles (RBAC - CU-12)
        $router->get('/admin/usuarios', [AdminController::class, 'usuarios'], [JwtAuthMiddleware::class]);
        $router->post('/admin/usuarios', [AdminController::class, 'crearUsuario'], [JwtAuthMiddleware::class]);
        $router->patch('/admin/usuarios/:id/rol', [AdminController::class, 'cambiarRol'], [JwtAuthMiddleware::class]);
        $router->patch('/admin/usuarios/:id/estado', [AdminController::class, 'cambiarEstado'], [JwtAuthMiddleware::class]);

        // 4. Configuración y Políticas del Sistema
        $router->get('/admin/configuracion', [AdminController::class, 'configuracion'], [JwtAuthMiddleware::class]);
        $router->put('/admin/configuracion', [AdminController::class, 'actualizarConfiguracion'], [JwtAuthMiddleware::class]);
    }
}
