<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Http\Request;
use App\Database\StoredProcedureCaller;

/**
 * Registro de actividad. Nunca hace fallar la acción principal.
 */
final class Actividad
{
    public static function registrar(
        Request $request,
        string $accion,
        string $descripcion,
        ?int $comercioId,
        string $entidad,
        int|string|null $entidadId
    ): void {
        $user = $request->getAttribute('user') ?? [];
        try {
            (new StoredProcedureCaller())->call('sp_actividad_registrar', [
                $accion,
                $descripcion,
                (int) ($user['usuario_id'] ?? 0),
                (int) ($user['turistas_id'] ?? 0),
                (int) $comercioId,
                $entidad,
                (int) $entidadId,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Actividad] ' . $e->getMessage());
        }
    }
}
