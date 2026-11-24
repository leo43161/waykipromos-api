<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\Database;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Support\Codigo;
use App\Support\Formato;

final class TuristaCuponesController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function emitir(Request $request): Response
    {
        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);
        $promocionId = (int) ($request->getParsedBody()['promocion_id'] ?? 0);
        if ($promocionId <= 0) {
            throw new ValidationException('Falta la promoción.', ['promocion_id' => 'Requerido']);
        }

        $r = null;
        $codigoEmitido = null;

        for ($intento = 1; ; $intento++) {
            $codigo = Codigo::nuevo();
            try {
                // 1. Intentar con sp_cupones_emitir
                $r = $this->sp->callSingle('sp_cupones_emitir', [$turistaId, $promocionId, $codigo, hash('sha256', $codigo)]);
                $codigoEmitido = (string) ($r['codigo'] ?? $codigo);
                break;
            } catch (\Throwable $e) {
                // 2. Fallback a sp_cupones_crear (los 124 SPs existentes)
                try {
                    $promo = $this->sp->callSingle('sp_promociones_obtener', [$promocionId]);
                    if ($promo === null) {
                        throw new NotFoundException('Esta promoción ya no está disponible.', 'PROMOCION_INEXISTENTE');
                    }
                    $venceEn = date('Y-m-d 23:59:59', strtotime((string) ($promo['fecha_fin'] ?? '+14 days')));
                    $qrHash = hash('sha256', $codigo);
                    
                    $this->sp->callWithOut('sp_cupones_crear', [
                        $codigo,
                        $qrHash,
                        $promocionId,
                        $venceEn,
                        $turistaId,
                    ]);
                    $r = ['resultado' => 'EMITIDO', 'codigo' => $codigo];
                    $codigoEmitido = $codigo;
                    break;
                } catch (\Throwable $ex) {
                    if ($intento >= 3) {
                        throw new ConflictException('No se pudo emitir el cupón en este momento.', 'ERROR_EMISION');
                    }
                }
            }
        }

        if ($r === null) {
            throw new ConflictException('No se pudo emitir el cupón en este momento.', 'ERROR_EMISION');
        }

        match ($r['resultado'] ?? '') {
            'EMITIDO', 'YA_LO_TENIA' => null,
            'ENCUESTA_PENDIENTE'      => throw new ConflictException('Antes de tu primer cupón, contanos de tu viaje.', 'ENCUESTA_PENDIENTE'),
            'PROMOCION_INEXISTENTE'   => throw new NotFoundException('Esta promoción ya no está disponible.', 'PROMOCION_INEXISTENTE'),
            'PROMOCION_NO_DISPONIBLE' => throw new ConflictException('Esta promoción no está disponible en este momento.', 'PROMOCION_NO_DISPONIBLE'),
            'STOCK_AGOTADO'           => throw new ConflictException('Se agotaron los cupones de esta promoción.', 'STOCK_AGOTADO'),
            default                   => null,
        };

        $cupon = null;
        try {
            $cupon = $this->sp->callSingle('sp_cupones_del_turista', [$turistaId, $codigoEmitido]);
        } catch (\Throwable) {
            $cupon = $this->buscarCupon($turistaId, $codigoEmitido);
        }

        if ($cupon === null) {
            throw new NotFoundException('No se pudo recuperar el cupón emitido.', 'CUPON_INEXISTENTE');
        }

        return Response::ok(Formato::cupon($cupon), null, ($r['resultado'] ?? '') === 'EMITIDO' ? 201 : 200);
    }

    public function listar(Request $request): Response
    {
        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);
        $filas = [];

        try {
            $filas = $this->sp->call('sp_cupones_del_turista', [$turistaId, '']);
        } catch (\Throwable) {
            $filas = $this->listarCuponesTurista($turistaId);
        }

        return Response::ok(array_map(Formato::cupon(...), $filas));
    }

    public function ver(Request $request, array $params): Response
    {
        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);
        $codigo = Codigo::normalizar($params['codigo'] ?? '');
        $fila = null;

        try {
            $fila = $this->sp->callSingle('sp_cupones_del_turista', [$turistaId, $codigo]);
        } catch (\Throwable) {
            $fila = $this->buscarCupon($turistaId, $codigo);
        }

        if ($fila === null) {
            throw new NotFoundException('No encontramos ese cupón en tu cuenta.', 'CUPON_INEXISTENTE');
        }

        return Response::ok(Formato::cupon($fila));
    }

    private function buscarCupon(int $turistaId, string $codigo): ?array
    {
        try {
            $stmt = Database::getConnection()->prepare("
                SELECT cu.cupon_id, cu.codigo, cu.emitido_en, cu.vence_en, cu.estado AS estado_cupon,
                       p.promocion_id, p.titulo, p.descripcion, p.condiciones, p.imagen_url,
                       p.fecha_inicio, p.fecha_fin, p.dias_validos, p.hora_inicio, p.hora_fin,
                       c.comercio_id, c.nombre AS comercio_nombre, c.direccion AS comercio_direccion,
                       c.telefono_contacto AS comercio_telefono, c.logo_url AS comercio_logo_url
                FROM cupones cu
                JOIN promociones p ON p.promocion_id = cu.promocion_id
                JOIN comercios c   ON c.comercio_id = p.comercio_id
                WHERE cu.turistas_id = :turista AND cu.codigo = :codigo AND cu.borrado = 0
                LIMIT 1
            ");
            $stmt->execute(['turista' => $turistaId, 'codigo' => $codigo]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function listarCuponesTurista(int $turistaId): array
    {
        try {
            $stmt = Database::getConnection()->prepare("
                SELECT cu.cupon_id, cu.codigo, cu.emitido_en, cu.vence_en, cu.estado AS estado_cupon,
                       p.promocion_id, p.titulo, p.descripcion, p.condiciones, p.imagen_url,
                       p.fecha_inicio, p.fecha_fin, p.dias_validos, p.hora_inicio, p.hora_fin,
                       c.comercio_id, c.nombre AS comercio_nombre, c.direccion AS comercio_direccion,
                       c.telefono_contacto AS comercio_telefono, c.logo_url AS comercio_logo_url
                FROM cupones cu
                JOIN promociones p ON p.promocion_id = cu.promocion_id
                JOIN comercios c   ON c.comercio_id = p.comercio_id
                WHERE cu.turistas_id = :turista AND cu.borrado = 0
                ORDER BY cu.vence_en, cu.emitido_en DESC
            ");
            $stmt->execute(['turista' => $turistaId]);
            return $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
