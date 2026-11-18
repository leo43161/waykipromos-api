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

// Verificación y carga del autoloader (soporte dual local y cPanel extraído)
$projectRoot = dirname(__DIR__);
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    $projectRoot . '/vendor/autoload.php',
];

$autoloadPath = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === null) {
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
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\FileLoggerMiddleware;
use App\Routes\ApiRoutes;

try {
    // 1. Cargar configuración dual: config.php (cPanel) o .env (desarrollo local)
    Env::loadConfigPhp(__DIR__ . '/config.php');
    Env::loadConfigPhp($projectRoot . '/config.php');
    Env::load($projectRoot . '/.env');
    Env::load(__DIR__ . '/.env');

    // 2. Inicializar Contenedor DI
    $container = new Container();

    // 3. Configurar Enrutador y registrar módulos de rutas
    $router = new Router($container);
    ApiRoutes::register($router);

    // 4. Pipeline y Despacho
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
