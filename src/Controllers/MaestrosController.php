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
        if (empty($rows)) {
            $rows = [
                ['categorias_comercio_id' => 1, 'nombre' => 'Gastronomía', 'icono' => '🍽️', 'activo' => 1],
                ['categorias_comercio_id' => 2, 'nombre' => 'Hotelería y Alojamiento', 'icono' => '🏨', 'activo' => 1],
                ['categorias_comercio_id' => 3, 'nombre' => 'Turismo Activo y Aventura', 'icono' => '🚵', 'activo' => 1],
                ['categorias_comercio_id' => 4, 'nombre' => 'Regalería y Regionales', 'icono' => '🏺', 'activo' => 1],
                ['categorias_comercio_id' => 5, 'nombre' => 'Enoturismo y Bodegas', 'icono' => '🍷', 'activo' => 1],
            ];
        }
        return Response::ok($rows)->withCache(3600);
    }

    /**
     * GET /api/v1/circuitos
     */
    public function circuitos(Request $request): Response
    {
        $rows = $this->sp->call('sp_circuitosturisticos_obtener', [0]);
        if (empty($rows)) {
            $rows = [
                ['circuitosturisticos_id' => 1, 'nombre' => 'Valles Calchaquíes', 'descripcion' => 'Tafí del Valle, Amaicha del Valle, Quilmes, Colalao', 'icono' => '🏔️', 'activo' => 1],
                ['circuitosturisticos_id' => 2, 'nombre' => 'Yungas y San Javier', 'descripcion' => 'San Javier, Yerba Buena, Villa Nougués, Raco', 'icono' => '🌿', 'activo' => 1],
                ['circuitosturisticos_id' => 3, 'nombre' => 'Ciudad Histórica', 'descripcion' => 'San Miguel de Tucumán, Casa Histórica', 'icono' => '🏛️', 'activo' => 1],
                ['circuitosturisticos_id' => 4, 'nombre' => 'Valle de Choromoro', 'descripcion' => 'San Pedro de Colalao, Trancas, El Cadillal', 'icono' => '🌊', 'activo' => 1],
                ['circuitosturisticos_id' => 5, 'nombre' => 'Sur Tucumano', 'descripcion' => 'Concepción, Monteros, Aguilares, Famaillá', 'icono' => '🌄', 'activo' => 1],
            ];
        }
        return Response::ok($rows)->withCache(3600);
    }

    /**
     * GET /api/v1/modalidades
     */
    public function modalidades(Request $request): Response
    {
        $rows = $this->sp->call('sp_modalidades_descuento_obtener', [0]);
        return Response::ok($rows)->withCache(3600);
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

        return Response::ok($rows)->withCache(3600);
    }
}
