<?php
// API de prueba de Wayki Promo: front controller. Todo pedido que no sea un archivo cae acá (.htaccess).
// Producción: https://tucumanturismo.gob.ar/waykipromo/api/   Local: ver README.md (php -S).

header('Content-Type: application/json; charset=utf-8');

// En local los front (Next en :3015 y :3001) están en otro puerto: CORS solo con el servidor de desarrollo de PHP.
// En producción front y API comparten origen (tucumanturismo.gob.ar) y no hace falta.
if (PHP_SAPI === 'cli-server' && isset($_SERVER['HTTP_ORIGIN']) && preg_match('#^http://localhost:\d+$#', $_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;
}

function responder($codigo, $datos) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Ruta relativa a la carpeta de la API: "/waykipromo/api/salud" → "/salud" (en local ya llega "/salud").
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($base !== '' && strpos($ruta, $base) === 0) $ruta = substr($ruta, strlen($base));
$ruta = '/' . trim($ruta, '/');

switch ($_SERVER['REQUEST_METHOD'] . ' ' . $ruta) {
    case 'GET /':
    case 'GET /salud':
        responder(200, ['ok' => true, 'servicio' => 'Wayki Promo API (prueba)', 'hora' => date('c')]);

    // TODO(backend): endpoints reales (cupones, guardar, canje…). Ver reglas en CLAUDE.md.

    default:
        responder(404, ['ok' => false, 'error' => "No existe $ruta"]);
}
