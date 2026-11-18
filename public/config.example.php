<?php
// Plantilla de configuración de la API de Wayki Promo para cPanel.
// Copiar este archivo a 'config.php' y definir las credenciales reales del servidor.
// 'config.php' está ignorado en Git (.gitignore) para no versionar secretos.

defined('WAYKI_API') or die('Acceso directo no permitido');

return [
    'env' => 'development', // 'development' o 'production'
    'port' => 3001,
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'huakipromo',
        'user'    => 'huakiuser',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'jwt' => [
        'secret'      => 'cambiar_por_clave_secreta_de_al_menos_32_caracteres',
        'ttl_seconds' => 86400 * 7, // 7 días
    ],
    'google' => [
        'client_id' => '', // ID de cliente OAuth 2.0 (ej: xxxx.apps.googleusercontent.com)
    ],
];
