<?php

declare(strict_types=1);

// Cabeceras CORS universales sin restricciones por el momento
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
$activeOrigin = $origin !== '' ? $origin : '*';

if (!headers_sent()) {
    header("Access-Control-Allow-Origin: {$activeOrigin}");
    if ($activeOrigin !== '*') {
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD');
    header('Access-Control-Allow-Headers: *');
    header('Access-Control-Max-Age: 86400');
}

// Responder inmediatamente a peticiones preflight OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
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
    // 1. Cargar variables de entorno buscando en ubicaciones seguras (Alternativa A: fuera de public_html)
    // En cPanel: __DIR__ es /home/<user>/public_html/waykipromo/api
    // dirname(__DIR__, 3) es /home/<user>/ (fuera de public_html, 100% blindado contra acceso web)
    $cpanelHome = dirname(__DIR__, 3);
    $envCandidates = [
        $cpanelHome . '/.env.waykipromo',
        $cpanelHome . '/.env',
        dirname(__DIR__, 2) . '/.env.waykipromo',
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__, 1) . '/.env.waykipromo',
        dirname(__DIR__, 1) . '/.env',
        $projectRoot . '/.env',
        __DIR__ . '/.env',
    ];

    foreach ($envCandidates as $candidate) {
        if (file_exists($candidate)) {
            Env::load($candidate);
            break;
        }
    }

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
