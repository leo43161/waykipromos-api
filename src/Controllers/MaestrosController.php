<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;

final class MaestrosController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    /**
     * GET /categorias
     */
    public function categorias(): Response
    {
        $rows = [];
        try {
            $rows = $this->sp->call('sp_categorias_comercio_obtener', [0]);
        } catch (\Throwable $e) {}

        if (empty($rows)) {
            $rows = [
                ['id' => 1, 'nombre' => 'Alojamiento'],
                ['id' => 2, 'nombre' => 'Gastronomía'],
                ['id' => 3, 'nombre' => 'Actividades y experiencias'],
                ['id' => 4, 'nombre' => 'Regalería y Regionales'],
                ['id' => 5, 'nombre' => 'Enoturismo y Bodegas'],
            ];
        } else {
            $rows = array_values(array_map(static fn (array $f): array => [
                'id'     => (int) ($f['categoria_id'] ?? ($f['categorias_comercio_id'] ?? ($f['id'] ?? 0))),
                'nombre' => $f['nombre'] ?? '',
            ], array_filter($rows, static fn ($f) => (int) ($f['activo'] ?? 1) === 1 && (int) ($f['borrado'] ?? 0) === 0)));
        }

        return Response::ok($rows, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * GET /modalidades
     */
    public function modalidades(): Response
    {
        $rows = [];
        try {
            $rows = $this->sp->call('sp_modalidades_descuento_obtener', [0]);
        } catch (\Throwable $e) {}

        if (empty($rows)) {
            $rows = [
                ['id' => 1, 'nombre' => '2x1', 'tipo' => 'PROMOCION', 'valor' => null],
                ['id' => 5, 'nombre' => '20%', 'tipo' => 'PORCENTAJE', 'valor' => 20],
                ['id' => 6, 'nombre' => 'Personalizado', 'tipo' => 'PERSONALIZADO', 'valor' => null],
            ];
        } else {
            $rows = array_values(array_map(static fn (array $f): array => [
                'id'     => (int) ($f['modalidad_id'] ?? ($f['id'] ?? 0)),
                'nombre' => $f['nombre'] ?? '',
                'tipo'   => $f['tipo'] ?? 'PROMOCION',
                'valor'  => ($f['valor'] ?? null) === null ? null : (float) $f['valor'],
            ], array_filter($rows, static fn ($f) => (int) ($f['activo'] ?? 1) === 1 && (int) ($f['borrado'] ?? 0) === 0)));
        }

        return Response::ok($rows, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * GET /circuitos
     */
    public function circuitos(): Response
    {
        $rows = [];
        try {
            $rows = $this->sp->call('sp_circuitosturisticos_obtener', [0]);
        } catch (\Throwable $e) {}

        if (empty($rows)) {
            $rows = [
                ['id' => 1, 'nombre' => 'Ciudad'],
                ['id' => 2, 'nombre' => 'Yungas'],
                ['id' => 3, 'nombre' => 'Valles Calchaquíes'],
                ['id' => 4, 'nombre' => 'Valle de Choromoro'],
                ['id' => 5, 'nombre' => 'Sur Tucumano'],
            ];
        } else {
            $rows = array_values(array_map(static fn (array $f): array => [
                'id'     => (int) ($f['circuitosturisticos_id'] ?? ($f['id'] ?? 0)),
                'nombre' => $f['nombre'] ?? '',
            ], array_filter($rows, static fn ($f) => (int) ($f['activo'] ?? 1) === 1 && (int) ($f['borrado'] ?? 0) === 0)));
        }

        return Response::ok($rows, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * GET /localidades
     */
    public function localidades(Request $request): Response
    {
        $provincia = (int) $request->getQueryParam('provincia_id', 1);
        $circuito = (int) $request->getQueryParam('circuito_id', 0);
        $rows = [];

        try {
            $filas = $this->sp->call('sp_localidades_obtener', [0]);
            $filas = array_filter($filas, static fn (array $f): bool =>
                (int) ($f['activo'] ?? 1) === 1 && (int) ($f['borrado'] ?? 0) === 0
                && (int) ($f['provincia_id'] ?? 1) === $provincia
                && ($circuito === 0 || (int) ($f['circuitosturisticos_id'] ?? 0) === $circuito)
            );
            usort($filas, static fn (array $a, array $b): int => strcoll((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? '')));
            $rows = array_values(array_map(static fn (array $f): array => [
                'id'          => (int) ($f['localidad_id'] ?? ($f['id'] ?? 0)),
                'nombre'      => $f['nombre'] ?? '',
                'circuito_id' => ($f['circuitosturisticos_id'] ?? null) === null ? null : (int) $f['circuitosturisticos_id'],
            ], $filas));
        } catch (\Throwable $e) {}

        if (empty($rows)) {
            $rows = [
                ['id' => 7, 'nombre' => 'Amaicha del Valle', 'circuito_id' => 3],
                ['id' => 86, 'nombre' => 'Tafí del Valle', 'circuito_id' => 3],
                ['id' => 103, 'nombre' => 'Yerba Buena - Marcos Paz', 'circuito_id' => 1],
            ];
        }

        return Response::ok($rows, null, 200, ['Cache-Control' => 'public, max-age=3600']);
    }
}
