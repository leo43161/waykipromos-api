<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\HealthController;
use App\Core\Router\Router;

class ApiRoutes
{
    /**
     * Registers all application routes into the Router.
     */
    public static function register(Router $router): void
    {
        // 1. Healthcheck & Raíz (Público)
        foreach (['/', '/health', '/salud', '/status', '/api', '/api/health', '/api/salud'] as $uri) {
            $router->get($uri, [HealthController::class, 'check']);
        }

        // 2. Rutas directas de autenticación (compatibilidad /auth/...)
        AuthRoutes::register($router);

        // 3. Módulos bajo prefijo unificado /v1 y /api/v1
        foreach (['/v1', '/api/v1'] as $prefix) {
            $router->group($prefix, function (Router $groupRouter): void {
                foreach (['/salud', '/health', '/status'] as $healthUri) {
                    $groupRouter->get($healthUri, [HealthController::class, 'check']);
                }

                // Contrato Oficial de la API v1 (46 endpoints canónicos)
                ContratoRoutes::register($groupRouter);

                // Rutas de compatibilidad histórica
                AuthRoutes::register($groupRouter);
                TuristaRoutes::register($groupRouter);
                ComercioRoutes::register($groupRouter);
                AdminRoutes::register($groupRouter);
            });
        }
    }
}
