<?php
// Descomprime el deploy de la API de Wayki Promo. Lo llama el último paso de .github/workflows/deploy.yml
// después de subir el ZIP por FTP a public_html/waykipromo/api/deploy.zip.
//
// Instalación (una sola vez, a mano): subir este archivo a public_html/waykipromo/api/descomprimir.php
// y reemplazar SECRETO por el mismo valor del secret UNZIP_SECRET del repo.
// Está en deploy/ y no en public/ a propósito: si viajara en el ZIP, cada deploy lo pisaría con 'REEMPLAZAR'.

const SECRETO = 'REEMPLAZAR';

function fin($codigo, $mensaje) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    exit($mensaje . "\n");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') fin(405, 'Método no permitido');
if (SECRETO === 'REEMPLAZAR') fin(500, 'Falta configurar SECRETO en descomprimir.php');

$secretEnviado = $_SERVER['HTTP_X_DEPLOY_SECRET'] ?? $_GET['secret'] ?? '';
if (!hash_equals(SECRETO, (string) $secretEnviado)) fin(403, 'Prohibido');

$zipRuta = __DIR__ . '/deploy.zip';
if (!is_file($zipRuta)) fin(404, 'No está el ZIP');

$zip = new ZipArchive();
if ($zip->open($zipRuta) !== true) fin(500, 'ZIP inválido');
$total = $zip->numFiles;
$ok = $zip->extractTo(__DIR__); // pisa archivos existentes; no borra los preexistentes (como el archivo .env del servidor)
$zip->close();
if (!$ok) fin(500, 'Falló la descompresión');

unlink($zipRuta);
fin(200, "OK: $total archivos en waykipromo/api/");
