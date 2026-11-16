<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\ValidationException;

class AdminController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    public function promocionesPendientes(Request $request): Response
    {
        $rows = $this->sp->call('sp_promociones_listar_pendientes');
        return Response::ok($rows);
    }

    public function aprobarPromocion(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? $request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            throw new ValidationException('Identificador de promoción inválido');
        }

        $this->sp->call('sp_promociones_actualizar_estado', [$id, 'PUBLICADA']);

        return Response::ok(['mensaje' => "Promoción #{$id} aprobada y publicada en la cuponera."]);
    }

    public function rechazarPromocion(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? $request->getAttribute('id') ?? 0);
        $body = $request->getParsedBody();
        $motivo = (string) ($body['motivo'] ?? 'Rechazada por no cumplir los criterios de la cuponera.');

        $this->sp->call('sp_promociones_actualizar_estado', [$id, 'RECHAZADA']);

        return Response::ok(['mensaje' => "Promoción #{$id} rechazada.", 'motivo' => $motivo]);
    }

    public function prestadores(Request $request): Response
    {
        $rows = $this->sp->call('sp_comercios_obtener', [0]);
        return Response::ok($rows);
    }

    public function metricasGlobales(Request $request): Response
    {
        $metricas = $this->sp->callSingle('sp_metricas_globales_obtener');
        return Response::ok($metricas ?: [
            'total_comercios'    => 0,
            'total_promociones'  => 0,
            'total_turistas'     => 0,
            'cupones_emitidos'   => 0,
            'cupones_canjeados'  => 0,
            'total_encuestas'    => 0,
        ]);
    }
}
