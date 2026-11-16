<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Env;
use App\Database\Database;
use App\Database\StoredProcedureCaller;

Env::load(dirname(__DIR__) . '/.env');

echo "========================================================\n";
echo " Smoke Test: Procedimientos Almacenados HUAKIPROMO\n";
echo "========================================================\n\n";

try {
    $pdo = Database::getConnection();
    echo "\033[32m[OK]\033[0m Conexión establecida con MySQL schema: " . Env::get('DB_NAME', 'huakipromo') . "\n\n";
} catch (\Throwable $e) {
    echo "\033[31m[ERROR]\033[0m No se pudo conectar a la base de datos:\n";
    echo "        " . $e->getMessage() . "\n";
    echo "\nVerifique las credenciales en el archivo .env\n";
    exit(1);
}

$spCaller = new StoredProcedureCaller($pdo);

// Procedimientos esenciales a comprobar con sus firmas exactas
$testProcedures = [
    'sp_categorias_comercio_obtener'    => [0],
    'sp_circuitosturisticos_obtener'    => [0],
    'sp_modalidades_descuento_obtener'  => [0],
    'sp_localidades_obtener'            => [0],
    'sp_comercios_obtener'              => [0],
    'sp_promociones_listar_publicas'    => [0, 0, 0, 0, ''],
    'sp_promociones_listar_gestion'     => [0, 0, ''],
    'sp_encuestas_turisticas_listar_detalle' => [0, 0],
];

$passed = 0;
$failed = 0;

foreach ($testProcedures as $spName => $params) {
    try {
        $rows = $spCaller->call($spName, $params);
        $count = count($rows);
        echo sprintf("  \033[32m✓\033[0m %-42s [OK] (%d registros)\n", $spName, $count);
        $passed++;
    } catch (\Throwable $e) {
        echo sprintf("  \033[31m✗\033[0m %-42s [FALLÓ]\n", $spName);
        echo "    \033[33mDetalle:\033[0m " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n--------------------------------------------------------\n";
echo "Resumen: {$passed} exitosos, {$failed} fallidos\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
