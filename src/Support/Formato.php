<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;
use App\Core\Http\Request;
use App\Domain\Exceptions\ValidationException;

/**
 * Filas de los procedimientos → objetos del contrato. Si cambia el contrato, se toca solo acá.
 */
final class Formato
{
    /**
     * "2026-11-19 10:05:49" → "2026-11-19T10:05:49-03:00"
     */
    public static function fecha(?string $mysql): ?string
    {
        return $mysql === null || $mysql === '' ? null : (new \DateTimeImmutable($mysql))->format(DATE_ATOM);
    }

    public static function url(?string $archivo): ?string
    {
        return $archivo === null || $archivo === '' ? null : rtrim((string) Env::get('UPLOADS_URL', ''), '/') . '/' . $archivo;
    }

    public static function horario(?string $desde, ?string $hasta): ?array
    {
        return $desde === null || $hasta === null ? null : [
            'desde' => substr($desde, 0, 5),
            'hasta' => substr($hasta, 0, 5),
        ];
    }

    public static function dias(?string $set): ?array
    {
        return $set === null || $set === '' ? null : explode(',', $set);
    }

    /**
     * Objeto Promocion (catálogo). Fila de vw_promociones.
     */
    public static function promocion(array $r): array
    {
        return [
            'id' => (int) $r['promocion_id'],
            'titulo' => $r['titulo'],
            'descripcion_corta' => mb_strimwidth((string) ($r['descripcion'] ?? ''), 0, 140, '…'),
            'comercio' => [
                'id' => (int) $r['comercio_id'],
                'nombre' => $r['comercio_nombre'],
                'lugar' => $r['localidad_nombre'],
            ],
            'categoria' => [
                'id' => (int) $r['categoria_id'],
                'nombre' => $r['categoria_nombre'],
            ],
            'descuento_visual' => $r['descuento_visual'],
            'fecha_desde' => $r['fecha_inicio'],
            'fecha_hasta' => $r['fecha_fin'],
            'dias_validos' => self::dias($r['dias_validos']),
            'horario' => self::horario($r['hora_inicio'], $r['hora_fin']),
            'cupos_disponibles' => (int) $r['stock_disponible'],
            'cupos_totales' => (int) $r['stock_total'],
            'imagen_url' => self::url($r['imagen_url']),
            'destacada' => (bool) $r['destacada'],
        ];
    }

    /**
     * Objeto PromocionFicha: la promoción + lo que hace falta para decidir guardarla.
     */
    public static function ficha(array $r): array
    {
        $p = self::promocion($r);
        $p['descripcion'] = $r['descripcion'];
        $p['condiciones'] = $r['condiciones'];
        $p['comercio'] += [
            'direccion' => $r['comercio_direccion'],
            'telefono' => $r['comercio_telefono'],
            'logo_url' => self::url($r['comercio_logo_url']),
        ];
        $p['circuito'] = $r['circuito_id'] === null ? null : [
            'id' => (int) $r['circuito_id'],
            'nombre' => $r['circuito_nombre'],
        ];
        return $p;
    }

    /**
     * Objeto Cupon (del turista). Fila de sp_cupones_del_turista.
     */
    public static function cupon(array $r): array
    {
        return [
            'codigo' => $r['codigo'],
            'estado' => $r['estado_cupon'],
            'emitido_el' => self::fecha($r['emitido_en']),
            'vence_el' => self::fecha($r['vence_en']),
            'canjeado_el' => self::fecha($r['canjeado_en']),
            'promocion' => self::promocion($r),
        ];
    }

    /**
     * ?pagina=1&limite=20 → límites seguros.
     */
    public static function paginacion(Request $request, int $maximo = 100): array
    {
        $pagina = max(1, (int) $request->getQueryParam('pagina', 1));
        $limite = min($maximo, max(1, (int) $request->getQueryParam('limite', 20)));
        return [
            'pagina' => $pagina,
            'limite' => $limite,
            'offset' => ($pagina - 1) * $limite,
        ];
    }

    /**
     * meta de los listados. Los procedimientos devuelven "total" en cada fila.
     */
    public static function meta(array $filas, array $paginacion): array
    {
        return [
            'pagina' => $paginacion['pagina'],
            'limite' => $paginacion['limite'],
            'total' => (int) ($filas[0]['total'] ?? count($filas)),
        ];
    }

    /**
     * ?desde=&hasta= (yyyy-mm-dd). Por defecto, los últimos 30 días. Máximo un año.
     */
    public static function periodo(Request $request): array
    {
        $valida = static fn (mixed $f): bool => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 && strtotime($f) !== false;
        $hasta = $request->getQueryParam('hasta');
        $desde = $request->getQueryParam('desde');
        $hasta = $valida($hasta) ? $hasta : date('Y-m-d');
        $desde = $valida($desde) ? $desde : date('Y-m-d', strtotime("$hasta -29 days"));
        if ($desde > $hasta || (strtotime($hasta) - strtotime($desde)) > 366 * 86400) {
            throw new ValidationException('Elegí un período de hasta un año.', ['desde' => 'Inválido', 'hasta' => 'Inválido']);
        }
        return [$desde, $hasta];
    }
}
