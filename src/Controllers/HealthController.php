<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\Database;
use Throwable;

class HealthController
{
    public function check(Request $request): Response
    {
        $dbStatus = 'disconnected';
        $dbError = null;

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query('SELECT 1');
            if ($stmt !== false) {
                $dbStatus = 'connected';
            }
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $nowTucuman = (new \DateTime('now', new \DateTimeZone('America/Argentina/Tucuman')))->format('Y-m-d H:i:s T');

        $isOk = ($dbStatus === 'connected');

        return Response::json([
            'ok' => $isOk,
            'data' => [
                'service' => 'API REST Wayki Promo (TuCupón)',
                'version' => '1.0.0',
                'status'  => $isOk ? 'healthy' : 'degraded',
                'php'     => PHP_VERSION,
                'time'    => $nowTucuman,
                'database' => [
                    'schema' => Env::get('DB_NAME', 'huakipromo'),
                    'status' => $dbStatus,
                    'error'  => $dbError,
                ],
            ],
        ], $isOk ? 200 : 503);
    }
}
