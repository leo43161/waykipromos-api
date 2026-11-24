<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\NotFoundException;
use App\Support\Acceso;
use App\Support\Formato;

final class ReportesController
{
    private const TOPE_CSV = 20000;

    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function resumen(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $hoy = date('Y-m-d');

        if (($user['rol'] ?? '') === 'empleado') {
            $canjes = [];
            try {
                $canjes = $this->sp->call('sp_canjes_listar', [Acceso::comercio($user), (int) ($user['usuario_id'] ?? 0), 0, $hoy, $hoy, 5, 0]);
            } catch (\Throwable) {}
            return Response::ok([
                'rol'            => 'empleado',
                'canjes_hoy'     => (int) ($canjes[0]['total'] ?? count($canjes)),
                'ultimos_canjes' => array_map(CanjesController::canje(...), $canjes),
            ]);
        }

        $comercio = Acceso::comercio($user, $request->getQueryParam('comercio_id'));
        [$desde, $hasta] = Formato::periodo($request);
        $filasReporte = [];
        try {
            $filasReporte = $this->sp->call('sp_reportes', [$desde, $hasta, $comercio, 0, 0]);
        } catch (\Throwable) {}

        $r = self::agrupar($filasReporte, $desde, $hasta);
        $actividad = [];
        try {
            $actividad = $this->sp->call('sp_actividad_listar', [$comercio, 0, '', null, null, 6, 0]);
        } catch (\Throwable) {}

        return Response::ok([
            'rol'             => $user['rol'] ?? 'encargado',
            'periodo'         => $r['periodo'],
            'totales'         => $r['totales'],
            'serie'           => $r['serie'],
            'promociones_top' => array_slice($r['grupos']['promocion'] ?? [], 0, 5),
            'categorias'      => $r['grupos']['categoria'] ?? [],
            'actividad'       => array_map(self::actividadItem(...), $actividad),
        ]);
    }

    public function reportes(Request $request): Response
    {
        [$desde, $hasta] = Formato::periodo($request);
        $filas = [];
        try {
            $filas = $this->sp->call('sp_reportes', [
                $desde,
                $hasta,
                (int) $request->getQueryParam('comercio_id', 0),
                (int) $request->getQueryParam('categoria_id', 0),
                (int) $request->getQueryParam('localidad_id', 0),
            ]);
        } catch (\Throwable) {}
        return Response::ok(self::agrupar($filas, $desde, $hasta));
    }

    public function actividad(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $pag = Formato::paginacion($request, 100);
        $periodo = $request->getQueryParam('desde') || $request->getQueryParam('hasta') ? Formato::periodo($request) : [null, null];
        $filas = [];
        try {
            $filas = $this->sp->call('sp_actividad_listar', [
                Acceso::comercio($user, $request->getQueryParam('comercio_id')),
                (int) $request->getQueryParam('usuario_id', 0),
                (string) $request->getQueryParam('accion', ''),
                $periodo[0],
                $periodo[1],
                $pag['limite'],
                $pag['offset'],
            ]);
        } catch (\Throwable) {}

        return Response::ok(array_map(self::actividadItem(...), $filas), Formato::meta($filas, $pag));
    }

    public function exportar(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $recurso = (string) ($params['recurso'] ?? '');

        if (in_array($recurso, ['reportes', 'visitantes', 'comercios'], true)) {
            Acceso::exigirEnte($user);
        }

        if (in_array($recurso, ['promociones', 'actividad'], true) && ($user['rol'] ?? '') === 'empleado') {
            throw new ForbiddenException('Tu usuario no puede exportar esto.', 'ROL_NO_AUTORIZADO');
        }

        $comercio = in_array($recurso, ['reportes', 'visitantes', 'comercios'], true)
            ? (int) $request->getQueryParam('comercio_id', 0)
            : Acceso::comercio($user, $request->getQueryParam('comercio_id'));

        [$desde, $hasta] = Formato::periodo($request);
        $q = mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100);

        [$filas, $columnas] = match ($recurso) {
            'canjes' => [
                $this->sp->call('sp_canjes_listar', [$comercio, ($user['rol'] ?? '') === 'empleado' ? (int) ($user['usuario_id'] ?? 0) : 0, 0, $desde, $hasta, self::TOPE_CSV, 0]),
                ['canjeado_en' => 'Fecha', 'codigo' => 'Código', 'titulo' => 'Promoción', 'descuento_visual' => 'Beneficio',
                 'comercio_nombre' => 'Comercio', 'turista_nombre' => 'Visitante', 'usuario_nombre' => 'Canjeó', 'observaciones' => 'Observaciones'],
            ],
            'promociones' => [
                $this->sp->call('sp_promociones_gestion', [0, $comercio, strtoupper((string) $request->getQueryParam('estado', '')), 0, $q, self::TOPE_CSV, 0]),
                ['titulo' => 'Promoción', 'comercio_nombre' => 'Comercio', 'localidad_nombre' => 'Localidad', 'categoria_nombre' => 'Categoría',
                 'descuento_visual' => 'Beneficio', 'estado' => 'Estado', 'fecha_inicio' => 'Desde', 'fecha_fin' => 'Hasta',
                 'stock_total' => 'Cupos', 'emitidos' => 'Entregados', 'canjeados' => 'Canjeados'],
            ],
            'actividad' => [
                $this->sp->call('sp_actividad_listar', [$comercio, 0, '', $desde, $hasta, self::TOPE_CSV, 0]),
                ['ocurrido_en' => 'Fecha', 'accion' => 'Acción', 'descripcion' => 'Detalle', 'usuario_nombre' => 'Usuario', 'comercio_nombre' => 'Comercio'],
            ],
            'visitantes' => [
                $this->sp->call('sp_visitantes_listar', [0, $q, 0, 0, 0, $desde, $hasta, self::TOPE_CSV, 0]),
                ['creado_en' => 'Registro', 'nombre' => 'Visitante', 'provincia' => 'Provincia', 'pais' => 'País', 'rango_edad' => 'Edad',
                 'duracion' => 'Estadía', 'motivo' => 'Motivo', 'compania' => 'Viaja', 'medio' => 'Nos conoció por',
                 'cupones_emitidos' => 'Cupones', 'cupones_canjeados' => 'Canjeados'],
            ],
            'comercios' => [
                $this->sp->call('sp_comercios_listar', [0, $q, '', 0, 0, self::TOPE_CSV, 0]),
                ['nombre' => 'Comercio', 'categoria_nombre' => 'Categoría', 'localidad_nombre' => 'Localidad', 'direccion' => 'Dirección',
                 'telefono_contacto' => 'Teléfono', 'email_contacto' => 'Email', 'estado_autorizacion' => 'Estado',
                 'promociones_publicadas' => 'Promociones publicadas', 'cupones_emitidos' => 'Cupones', 'cupones_canjeados' => 'Canjeados'],
            ],
            'reportes' => [
                $this->sp->call('sp_reportes', [$desde, $hasta, $comercio, (int) $request->getQueryParam('categoria_id', 0), (int) $request->getQueryParam('localidad_id', 0)]),
                ['grupo' => 'Grupo', 'etiqueta' => 'Indicador', 'cantidad' => 'Cantidad', 'canjeados' => 'Canjeados'],
            ],
            default => throw new NotFoundException('No se puede exportar eso.', 'RECURSO_INEXISTENTE'),
        };

        $csv = fopen('php://temp', 'r+');
        fwrite($csv, "\xEF\xBB\xBF");
        fputcsv($csv, array_values($columnas), ';', '"', '');
        foreach ($filas as $fila) {
            fputcsv($csv, array_map(static fn (string $k): string => self::celda($fila[$k] ?? ''), array_keys($columnas)), ';', '"', '');
        }
        rewind($csv);

        return new Response(200, (string) stream_get_contents($csv), [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="wayki-%s-%s.csv"', $recurso, date('Y-m-d')),
        ]);
    }

    public static function agrupar(array $filas, string $desde, string $hasta): array
    {
        $r = [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'totales' => [],
            'serie'   => [],
            'grupos'  => [],
        ];

        foreach ($filas as $f) {
            $grupo = (string) ($f['grupo'] ?? '');
            if ($grupo === 'total') {
                $r['totales'][$f['etiqueta']] = (int) $f['cantidad'];
            } elseif ($grupo === 'dia') {
                $r['serie'][] = [
                    'fecha'     => $f['etiqueta'],
                    'emitidos'  => (int) $f['cantidad'],
                    'canjeados' => (int) ($f['canjeados'] ?? 0),
                ];
            } else {
                $r['grupos'][$grupo][] = array_filter([
                    'id'        => ($f['id'] ?? null) === null ? null : (int) $f['id'],
                    'etiqueta'  => $f['etiqueta'],
                    'cantidad'  => (int) $f['cantidad'],
                    'canjeados' => ($f['canjeados'] ?? null) === null ? null : (int) $f['canjeados'],
                ], static fn ($v) => $v !== null);
            }
        }

        usort($r['serie'], static fn (array $a, array $b): int => strcmp($a['fecha'], $b['fecha']));
        $emitidos = $r['totales']['emitidos'] ?? 0;
        $r['totales']['tasa_canje'] = $emitidos > 0 ? round(($r['totales']['canjeados'] ?? 0) / $emitidos, 3) : 0;

        return $r;
    }

    public static function actividadItem(array $f): array
    {
        return [
            'id'          => (int) ($f['actividad_id'] ?? 0),
            'ocurrido_el' => Formato::fecha($f['ocurrido_en'] ?? null),
            'accion'      => $f['accion'] ?? '',
            'descripcion' => $f['descripcion'] ?? '',
            'usuario'     => empty($f['usuario_id']) ? null : [
                'id'     => (int) $f['usuario_id'],
                'nombre' => $f['usuario_nombre'] ?? '',
                'rol'    => $f['usuario_rol'] ?? '',
            ],
            'comercio'    => empty($f['comercio_id']) ? null : [
                'id'     => (int) $f['comercio_id'],
                'nombre' => $f['comercio_nombre'] ?? '',
            ],
            'entidad'     => empty($f['entidad']) ? null : [
                'tipo' => $f['entidad'],
                'id'   => (int) ($f['entidad_id'] ?? 0),
            ],
        ];
    }

    private static function celda(mixed $valor): string
    {
        $s = (string) $valor;
        return preg_match('/^[=+\-@\t\r]/', $s) === 1 ? "'" . $s : $s;
    }
}
