<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;

class MaestrosController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller()
    ) {}

    /**
     * GET /api/v1/categorias
     */
    public function categorias(Request $request): Response
    {
        $rows = $this->sp->call('sp_categorias_comercio_obtener', [0]);
        return Response::ok($rows);
    }

    /**
     * GET /api/v1/circuitos
     */
    public function circuitos(Request $request): Response
    {
        $rows = $this->sp->call('sp_circuitosturisticos_obtener', [0]);
        return Response::ok($rows);
    }

    /**
     * GET /api/v1/modalidades
     */
    public function modalidades(Request $request): Response
    {
        $rows = $this->sp->call('sp_modalidades_descuento_obtener', [0]);
        return Response::ok($rows);
    }

    /**
     * GET /api/v1/localidades
     */
    public function localidades(Request $request): Response
    {
        $circuitoId = (int) ($request->getQueryParam('circuito_id') ?? 0);
        $rows = $circuitoId > 0
            ? $this->sp->call('sp_localidades_listar_por_circuito', [$circuitoId])
            : $this->sp->call('sp_localidades_obtener', [0]);

        return Response::ok($rows);
    }
}
