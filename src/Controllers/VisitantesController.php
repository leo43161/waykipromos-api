<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\NotFoundException;
use App\Support\Formato;

final class VisitantesController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function listar(Request $request): Response
    {
        $pag = Formato::paginacion($request, 100);
        $periodo = $request->getQueryParam('desde') || $request->getQueryParam('hasta') ? Formato::periodo($request) : [null, null];

        $filas = $this->sp->call('sp_visitantes_listar', [
            0,
            mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100),
            (int) $request->getQueryParam('provincia_id', 0),
            (int) $request->getQueryParam('pais_id', 0),
            (int) $request->getQueryParam('rango_edad_id', 0),
            $periodo[0],
            $periodo[1],
            $pag['limite'],
            $pag['offset'],
        ]);

        return Response::ok(array_map(self::visitante(...), $filas), Formato::meta($filas, $pag));
    }

    public function ver(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $fila = $this->sp->callSingle('sp_visitantes_listar', [$id, '', 0, 0, 0, null, null, 1, 0])
            ?? throw new NotFoundException('No existe ese visitante.', 'NO_ENCONTRADO');

        return Response::ok(self::visitante($fila));
    }

    public static function visitante(array $f): array
    {
        return [
            'id'                => (int) ($f['turistas_id'] ?? 0),
            'nombre'            => $f['nombre'] ?? '',
            'procedencia'       => $f['provincia'] ?? ($f['pais'] ?? 'Desconocida'),
            'provincia'         => empty($f['provincia_id']) ? null : ['id' => (int) $f['provincia_id'], 'nombre' => $f['provincia']],
            'pais'              => empty($f['pais_id']) ? null : ['id' => (int) $f['pais_id'], 'nombre' => $f['pais']],
            'rango_edad'        => $f['rango_edad'] ?? null,
            'duracion'          => $f['duracion'] ?? null,
            'motivo'            => $f['motivo'] ?? null,
            'compania'          => $f['compania'] ?? null,
            'medio'             => $f['medio'] ?? null,
            'cupones_emitidos'  => (int) ($f['cupones_emitidos'] ?? 0),
            'cupones_canjeados' => (int) ($f['cupones_canjeados'] ?? 0),
            'registrado_el'     => Formato::fecha($f['creado_en'] ?? null),
            'ultimo_acceso'     => Formato::fecha($f['ultimo_acceso'] ?? null),
        ];
    }
}
