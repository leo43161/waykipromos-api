<?php

declare(strict_types=1);

// Cabeceras CORS universales a nivel de runtime
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('CORS_ALLOWED_ORIGINS') ?: getenv('ALLOWED_ORIGINS') ?: 'https://waykipromo.tucumanturismo.gob.ar,http://localhost:3000,http://localhost:4321,http://localhost:5173'))));
$originAllowed = in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true);
$activeOrigin = $originAllowed ? $origin : '';

if (!headers_sent()) {
    if ($activeOrigin !== '') {
        header("Access-Control-Allow-Origin: {$activeOrigin}");
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
    header('Access-Control-Max-Age: 600');
    header('Access-Control-Allow-Credentials: true');
}

// Responder inmediatamente a peticiones preflight OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code($originAllowed ? 204 : 403);
    exit;
}

// Captura de errores fatales de bajo nivel
register_shutdown_function(function () use ($activeOrigin): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            if ($activeOrigin !== '') {
                header("Access-Control-Allow-Origin: {$activeOrigin}");
                header('Vary: Origin');
            }
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'    => false,
            'error' => [
                'codigo'   => 'ERROR_FATAL_PHP',
                'mensaje'  => 'Error fatal en runtime PHP: ' . $error['message'],
                'detalles' => [
                    'file' => $error['file'],
                    'line' => $error['line'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

// Verificación y carga del autoloader
$projectRoot = dirname(__DIR__);
$autoloadPath = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'    => false,
        'error' => [
            'codigo'  => 'FALTA_AUTOLOADER',
            'mensaje' => 'Falta el autoloader de Composer (vendor/autoload.php). Ejecutar composer dump-autoload.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once $autoloadPath;

use App\Config\Env;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CanjesController;
use App\Controllers\ComercioPromocionesController;
use App\Controllers\CuponesController;
use App\Controllers\EncuestasController;
use App\Controllers\HealthController;
use App\Controllers\MaestrosController;
use App\Controllers\OfertasController;
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\FileLoggerMiddleware;
use App\Middleware\JwtAuthMiddleware;

try {
    // 1. Cargar variables de entorno
    Env::load($projectRoot . '/.env');

    // 2. Inicializar Contenedor DI
    $container = new Container();

    // 3. Configurar Enrutador
    $router = new Router($container);

    // ==========================================
    // 1. Healthcheck & Raíz
    // ==========================================
    foreach (['/', '/health', '/api', '/api/health', '/v1/health', '/api/v1/health'] as $uri) {
        $router->get($uri, [HealthController::class, 'check']);
    }

    // ==========================================
    // 2. Autenticación y Perfil
    // ==========================================
    foreach (['/v1/auth/google', '/api/v1/auth/google'] as $uri) {
        $router->post($uri, [AuthController::class, 'googleLogin']);
    }
    foreach (['/v1/auth/login', '/api/v1/auth/login'] as $uri) {
        $router->post($uri, [AuthController::class, 'emailLogin']);
    }
    foreach (['/v1/auth/perfil', '/api/v1/auth/perfil'] as $uri) {
        $router->get($uri, [AuthController::class, 'profile'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/auth/logout', '/api/v1/auth/logout'] as $uri) {
        $router->post($uri, [AuthController::class, 'logout']);
    }

    // ==========================================
    // 3. Catálogos Maestros (Público)
    // ==========================================
    foreach (['/v1/categorias', '/api/v1/categorias'] as $uri) {
        $router->get($uri, [MaestrosController::class, 'categorias']);
    }
    foreach (['/v1/circuitos', '/api/v1/circuitos'] as $uri) {
        $router->get($uri, [MaestrosController::class, 'circuitos']);
    }
    foreach (['/v1/modalidades', '/api/v1/modalidades'] as $uri) {
        $router->get($uri, [MaestrosController::class, 'modalidades']);
    }
    foreach (['/v1/localidades', '/api/v1/localidades'] as $uri) {
        $router->get($uri, [MaestrosController::class, 'localidades']);
    }

    // ==========================================
    // 4. Catálogo Público de Ofertas
    // ==========================================
    foreach (['/v1/ofertas', '/api/v1/ofertas'] as $uri) {
        $router->get($uri, [OfertasController::class, 'index']);
    }
    foreach (['/v1/ofertas/:id', '/api/v1/ofertas/:id'] as $uri) {
        $router->get($uri, [OfertasController::class, 'show']);
    }

    // ==========================================
    // 5. Motor de Cupones (Turistas)
    // ==========================================
    foreach (['/v1/cupones', '/api/v1/cupones'] as $uri) {
        $router->post($uri, [CuponesController::class, 'emitir'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/mis-cupones', '/api/v1/mis-cupones'] as $uri) {
        $router->get($uri, [CuponesController::class, 'misCupones'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/cupones/:codigo', '/api/v1/cupones/:codigo'] as $uri) {
        $router->get($uri, [CuponesController::class, 'show']);
    }

    // ==========================================
    // 6. Validación y Canje en Mostrador (Comercio)
    // ==========================================
    foreach (['/v1/canjes/validar', '/api/v1/canjes/validar'] as $uri) {
        $router->post($uri, [CanjesController::class, 'validar'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/canjes/confirmar', '/api/v1/canjes/confirmar'] as $uri) {
        $router->post($uri, [CanjesController::class, 'confirmar'], [JwtAuthMiddleware::class]);
    }

    // ==========================================
    // 7. Gestión Comercial (Encargados de Comercios)
    // ==========================================
    foreach (['/v1/comercio/promociones', '/api/v1/comercio/promociones'] as $uri) {
        $router->get($uri, [ComercioPromocionesController::class, 'index'], [JwtAuthMiddleware::class]);
        $router->post($uri, [ComercioPromocionesController::class, 'create'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/comercio/promociones/:id', '/api/v1/comercio/promociones/:id'] as $uri) {
        $router->put($uri, [ComercioPromocionesController::class, 'update'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/comercio/historial-canjes', '/api/v1/comercio/historial-canjes'] as $uri) {
        $router->get($uri, [ComercioPromocionesController::class, 'historialCanjes'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/comercio/metricas', '/api/v1/comercio/metricas'] as $uri) {
        $router->get($uri, [ComercioPromocionesController::class, 'metricas'], [JwtAuthMiddleware::class]);
    }

    // ==========================================
    // 8. Encuestas de Perfil Turístico (Observatorio)
    // ==========================================
    foreach (['/v1/encuestas', '/api/v1/encuestas'] as $uri) {
        $router->post($uri, [EncuestasController::class, 'guardar']);
    }

    // ==========================================
    // 9. Administración y Fiscalización (Ente)
    // ==========================================
    foreach (['/v1/admin/promociones/pendientes', '/api/v1/admin/promociones/pendientes'] as $uri) {
        $router->get($uri, [AdminController::class, 'promocionesPendientes'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/admin/promociones/:id/aprobar', '/api/v1/admin/promociones/:id/aprobar'] as $uri) {
        $router->post($uri, [AdminController::class, 'aprobarPromocion'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/admin/promociones/:id/rechazar', '/api/v1/admin/promociones/:id/rechazar'] as $uri) {
        $router->post($uri, [AdminController::class, 'rechazarPromocion'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/admin/prestadores', '/api/v1/admin/prestadores'] as $uri) {
        $router->get($uri, [AdminController::class, 'prestadores'], [JwtAuthMiddleware::class]);
    }
    foreach (['/v1/admin/metricas-globales', '/api/v1/admin/metricas-globales'] as $uri) {
        $router->get($uri, [AdminController::class, 'metricasGlobales'], [JwtAuthMiddleware::class]);
    }

    // ==========================================
    // 10. Pipeline y Despacho
    // ==========================================
    $pipeline = new Pipeline([
        new CorsMiddleware(),
        new ErrorHandlerMiddleware(),
        new FileLoggerMiddleware(),
    ], $router);

    $request = Request::createFromGlobals();
    $response = $pipeline->handle($request);
    $response->send();
} catch (\Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        if ($activeOrigin !== '') {
            header("Access-Control-Allow-Origin: {$activeOrigin}");
            header('Vary: Origin');
        }
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok'    => false,
        'error' => [
            'codigo'  => 'ERROR_INICIALIZACION_API',
            'mensaje' => 'Fallo crítico al inicializar la API: ' . $e->getMessage(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
