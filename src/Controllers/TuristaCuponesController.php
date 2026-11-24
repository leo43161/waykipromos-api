<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
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
        for ($intento = 1; ; $intento++) {
            $codigo = Codigo::nuevo();
            try {
                $r = $this->sp->callSingle('sp_cupones_emitir', [$turistaId, $promocionId, $codigo, hash('sha256', $codigo)]);
                break;
            } catch (ConflictException $e) {
                if ($intento >= 3) {
                    throw $e;
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

        $cupon = $this->sp->callSingle('sp_cupones_del_turista', [$turistaId, (string) ($r['codigo'] ?? '')]);
        if ($cupon === null) {
            throw new NotFoundException('No se pudo recuperar el cupón emitido.', 'CUPON_INEXISTENTE');
        }

        return Response::ok(Formato::cupon($cupon), null, ($r['resultado'] ?? '') === 'EMITIDO' ? 201 : 200);
    }

    public function listar(Request $request): Response
    {
        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);
        $filas = $this->sp->call('sp_cupones_del_turista', [$turistaId, '']);
        return Response::ok(array_map(Formato::cupon(...), $filas));
    }

    public function ver(Request $request, array $params): Response
    {
        $turistaId = (int) ($request->getAttribute('user')['turistas_id'] ?? 0);
        $fila = $this->sp->callSingle('sp_cupones_del_turista', [
            $turistaId,
            Codigo::normalizar($params['codigo'] ?? ''),
        ]) ?? throw new NotFoundException('No encontramos ese cupón en tu cuenta.', 'CUPON_INEXISTENTE');

        return Response::ok(Formato::cupon($fila));
    }
}
