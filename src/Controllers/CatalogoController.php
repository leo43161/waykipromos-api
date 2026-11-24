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
        $filas = $this->sp->call('sp_promociones_catalogo', [
            0,
            (int) $request->getQueryParam('categoria_id', 0),
            (int) $request->getQueryParam('localidad_id', 0),
            (int) $request->getQueryParam('circuito_id', 0),
            mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100),
            $pag['limite'],
            $pag['offset'],
        ]);
        return Response::ok(array_map(Formato::promocion(...), $filas), Formato::meta($filas, $pag));
    }

    public function ficha(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $fila = $this->sp->callSingle('sp_promociones_catalogo', [$id, 0, 0, 0, '', 1, 0])
            ?? throw new NotFoundException('Esta promoción ya no está disponible.', 'PROMOCION_INEXISTENTE');
        return Response::ok(Formato::ficha($fila));
    }
}
