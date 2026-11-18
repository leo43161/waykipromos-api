<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AuthController;
use App\Core\Router\Router;
use App\Middleware\JwtAuthMiddleware;

class AuthRoutes
{
    public static function register(Router $router): void
    {
        $router->post('/auth/google', [AuthController::class, 'googleLogin']);
        $router->post('/auth/login', [AuthController::class, 'emailLogin']);
        $router->get('/auth/perfil', [AuthController::class, 'profile'], [JwtAuthMiddleware::class]);
        $router->get('/auth/me', [AuthController::class, 'profile'], [JwtAuthMiddleware::class]);
        $router->post('/auth/logout', [AuthController::class, 'logout']);
    }
}
