<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\NotFoundException;
use App\Support\Formato;

final class CatalogoController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function listar(Request $request): Response
    {
        $pag = Formato::paginacion($request, 100);
        $catId = (int) $request->getQueryParam('categoria_id', 0);
        $comId = (int) $request->getQueryParam('comercio_id', 0);
        $modId = (int) $request->getQueryParam('modalidad_id', 0);
        $cirId = (int) $request->getQueryParam('circuito_id', 0);
        $q     = mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100);

        $filas = [];
        try {
            // 1. Intentar con SP oficial de 124 SPs: sp_promociones_listar_publicas
            $filas = $this->sp->call('sp_promociones_listar_publicas', [$catId, $comId, $modId, $cirId, $q]);
        } catch (\Throwable) {
            try {
                // 2. Intentar con sp_promociones_catalogo
                $filas = $this->sp->call('sp_promociones_catalogo', [
                    0,
                    $catId,
                    (int) $request->getQueryParam('localidad_id', 0),
                    $cirId,
                    $q,
                    $pag['limite'],
                    $pag['offset'],
                ]);
            } catch (\Throwable) {
                $filas = [];
            }
        }

        $total = count($filas);
        $offset = $pag['offset'];
        $limite = $pag['limite'];
        $slice = ($total > $limite || $offset > 0) ? array_slice($filas, $offset, $limite) : $filas;

        return Response::ok(array_map(Formato::promocion(...), $slice), [
            'total'   => (int) ($filas[0]['total'] ?? $total),
            'pagina'  => $pag['pagina'],
            'limite'  => $limite,
            'paginas' => (int) ceil(max(1, $total) / max(1, $limite)),
        ]);
    }

    public function ficha(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $fila = null;

        try {
            // Intentar con SP oficial de 124 SPs: sp_promociones_detalle_publico
            $fila = $this->sp->callSingle('sp_promociones_detalle_publico', [$id]);
        } catch (\Throwable) {}

        if ($fila === null) {
            try {
                $fila = $this->sp->callSingle('sp_promociones_obtener', [$id]);
            } catch (\Throwable) {}
        }

        if ($fila === null) {
            try {
                $fila = $this->sp->callSingle('sp_promociones_catalogo', [$id, 0, 0, 0, '', 1, 0]);
            } catch (\Throwable) {}
        }

        if ($fila === null) {
            throw new NotFoundException('Esta promoción ya no está disponible.', 'PROMOCION_INEXISTENTE');
        }

        return Response::ok(Formato::ficha($fila));
    }
}
