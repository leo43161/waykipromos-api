<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\NotFoundException;

class OfertasController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * GET /api/v1/ofertas
     * Catálogo público de ofertas vigentes con soporte para filtros y paginación.
     */
    public function index(Request $request): Response
    {
        $categoriaId = (int) ($request->getQueryParam('categoria_id') ?? 0);
        $circuitoId  = (int) ($request->getQueryParam('circuito_id') ?? 0);
        $localidadId = (int) ($request->getQueryParam('localidad_id') ?? 0);
        $q           = trim((string) ($request->getQueryParam('q') ?? ''));
        $pagina      = max(1, (int) ($request->getQueryParam('pagina') ?? 1));
        $limite      = min(50, max(1, (int) ($request->getQueryParam('limite') ?? 20)));

        // Consulta de ofertas públicas
        $rows = $this->sp->call('sp_promociones_listar_publicas', [
            $categoriaId,
            $circuitoId,
            $localidadId,
            $q,
        ]);

        $total = count($rows);
        $offset = ($pagina - 1) * $limite;
        $items = array_slice($rows, $offset, $limite);

        return Response::ok($items, [
            'total'  => $total,
            'pagina' => $pagina,
            'limite' => $limite,
            'paginas'=> (int) ceil($total / $limite),
        ]);
    }

    /**
     * GET /api/v1/ofertas/:id
     * Ficha técnica detallada de una promoción.
     */
    public function show(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? $request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            throw new NotFoundException('Identificador de oferta inválido');
        }

        $oferta = $this->sp->callSingle('sp_promociones_obtener_detalle_publico', [$id]);

        if ($oferta === null) {
            throw new NotFoundException('La oferta no existe o no se encuentra disponible.');
        }

        return Response::ok($oferta);
    }
}
