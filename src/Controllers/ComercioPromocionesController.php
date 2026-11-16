<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\UnauthorizedException;
use App\Domain\Exceptions\ValidationException;

class ComercioPromocionesController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    public function index(Request $request): Response
    {
        $comercioId = $this->getComercioId($request);
        $promociones = $this->sp->call('sp_promociones_listar_por_comercio', [$comercioId]);

        return Response::ok($promociones);
    }

    public function create(Request $request): Response
    {
        $comercioId = $this->getComercioId($request);
        $user = $request->getAttribute('user');
        $usuarioId = (int) ($user['usuario_id'] ?? 1);

        $body = $request->getParsedBody();
        $titulo = trim((string) ($body['titulo'] ?? ''));
        $modalidadId = (int) ($body['modalidad_id'] ?? 0);
        $categoriaId = (int) ($body['categoria_id'] ?? 1);
        $circuitoId  = (int) ($body['circuitosturisticos_id'] ?? $body['circuito_id'] ?? 1);
        $stockTotal  = (int) ($body['stock_total'] ?? 100);
        $fechaInicio = (string) ($body['fecha_inicio'] ?? date('Y-m-d'));
        $fechaFin    = (string) ($body['fecha_fin'] ?? date('Y-m-d', strtotime('+30 days')));

        if ($titulo === '' || $modalidadId <= 0) {
            throw new ValidationException('Título y Modalidad son obligatorios.');
        }

        $nuevoId = $this->sp->callWithOut('sp_promociones_crear', [
            $comercioId,
            $titulo,
            (string) ($body['descripcion'] ?? ''),
            $modalidadId,
            (string) ($body['modalidad_personalizada'] ?? null),
            $fechaInicio,
            $fechaFin,
            (string) ($body['dias_validos'] ?? 'LUN,MAR,MIE,JUE,VIE,SAB,DOM'),
            (string) ($body['hora_inicio'] ?? '00:00:00'),
            (string) ($body['hora_fin'] ?? '23:59:59'),
            (string) ($body['condiciones'] ?? ''),
            $stockTotal,
            $stockTotal, // stock_disponible inicial
            'PENDIENTE', // Pasa a fiscalización del Ente por defecto
            $usuarioId,
            $circuitoId,
            $categoriaId,
            0, // orden
        ]);

        return Response::ok([
            'promocion_id' => (int) $nuevoId,
            'mensaje'      => 'Promoción creada exitosamente y remitida a fiscalización.',
        ], null, 201);
    }

    public function update(Request $request, array $params = []): Response
    {
        $comercioId = $this->getComercioId($request);
        $id = (int) ($params['id'] ?? $request->getAttribute('id') ?? 0);

        $promo = $this->sp->callSingle('sp_promociones_obtener', [$id]);
        if ($promo === null || (int) $promo['comercio_id'] !== $comercioId) {
            throw new NotFoundException('Promoción no encontrada o no pertenece a su comercio.');
        }

        $body = $request->getParsedBody();

        $this->sp->call('sp_promociones_actualizar', [
            $id,
            $comercioId,
            (string) ($body['titulo'] ?? $promo['titulo']),
            (string) ($body['descripcion'] ?? $promo['descripcion']),
            (int) ($body['modalidad_id'] ?? $promo['modalidad_id']),
            (string) ($body['modalidad_personalizada'] ?? $promo['modalidad_personalizada']),
            (string) ($body['fecha_inicio'] ?? $promo['fecha_inicio']),
            (string) ($body['fecha_fin'] ?? $promo['fecha_fin']),
            (string) ($body['dias_validos'] ?? $promo['dias_validos']),
            (string) ($body['hora_inicio'] ?? $promo['hora_inicio']),
            (string) ($body['hora_fin'] ?? $promo['hora_fin']),
            (string) ($body['condiciones'] ?? $promo['condiciones']),
            (int) ($body['stock_total'] ?? $promo['stock_total']),
            (int) ($body['stock_disponible'] ?? $promo['stock_disponible']),
            (string) ($body['estado'] ?? $promo['estado']),
            (int) ($body['circuitosturisticos_id'] ?? $promo['circuitosturisticos_id']),
            (int) ($body['categoria_id'] ?? $promo['categoria_id']),
            (int) ($body['orden'] ?? $promo['orden']),
        ]);

        return Response::ok(['mensaje' => 'Promoción actualizada correctamente.']);
    }

    public function historialCanjes(Request $request): Response
    {
        $comercioId = $this->getComercioId($request);
        $canjes = $this->sp->call('sp_canjes_listar_por_comercio', [$comercioId]);

        return Response::ok($canjes);
    }

    public function metricas(Request $request): Response
    {
        $comercioId = $this->getComercioId($request);
        $metricas = $this->sp->callSingle('sp_metricas_comercio_obtener', [$comercioId]);

        return Response::ok($metricas ?: [
            'total_promociones' => 0,
            'cupones_emitidos'  => 0,
            'cupones_canjeados' => 0,
            'tasa_conversion'   => 0.0,
        ]);
    }

    private function getComercioId(Request $request): int
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('No autenticado');
        }

        $comercioId = (int) ($user['comercio_id'] ?? 0);
        if ($comercioId <= 0 && !in_array((string) ($user['rol'] ?? ''), ['admin_ente', 'superadmin'], true)) {
            throw new ForbiddenException('El usuario no se encuentra asignado a ningún comercio.');
        }

        return $comercioId;
    }
}
