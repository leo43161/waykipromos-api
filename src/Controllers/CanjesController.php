<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\HttpException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Support\Acceso;
use App\Support\Actividad;
use App\Support\Codigo;
use App\Support\Formato;

final class CanjesController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    /**
     * Solo mira: no canjea. El empleado ve qué incluye y confirma después.
     */
    public function validar(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $codigo = self::codigo($request);
        $fila = $this->buscar($codigo, Acceso::comercioCanje($user));
        if (($fila['problema'] ?? null) !== null) {
            throw self::problema($fila);
        }
        return Response::ok(self::cupon($fila));
    }

    /**
     * Canjea. Atómico: si dos empleados confirman a la vez, uno solo gana; el otro recibe CUPON_YA_CANJEADO.
     */
    public function confirmar(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $codigo = self::codigo($request);
        $comercio = Acceso::comercioCanje($user);
        $obs = mb_substr(trim((string) ($request->getParsedBody()['observaciones'] ?? '')), 0, 500);

        $r = $this->sp->callSingle('sp_canjes_confirmar', [$codigo, (int) ($user['usuario_id'] ?? 0), $comercio, $obs]);
        $fila = $this->buscar($codigo, $comercio);

        if (($r['resultado'] ?? '') !== 'CANJEADO') {
            throw self::problema($fila);
        }

        Actividad::registrar(
            $request,
            'CUPON_CANJEADO',
            sprintf('%s canjeó %s (%s)', $user['nombre'] ?? 'Empleado', $codigo, $fila['titulo'] ?? ''),
            (int) ($fila['comercio_id'] ?? 0),
            'cupon',
            (int) ($fila['cupon_id'] ?? 0)
        );

        return Response::ok([
            'canje_id'    => (int) ($r['canje_id'] ?? 0),
            'canjeado_el' => Formato::fecha($r['canjeado_en'] ?? date('Y-m-d H:i:s')),
        ] + self::cupon($fila), null, 201);
    }

    public function listar(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $pag = Formato::paginacion($request, 100);
        [$desde, $hasta] = Formato::periodo($request);
        $soloMios = (($user['rol'] ?? '') === 'empleado');

        $filas = $this->sp->call('sp_canjes_listar', [
            Acceso::comercio($user, $request->getQueryParam('comercio_id')),
            $soloMios ? (int) ($user['usuario_id'] ?? 0) : (int) $request->getQueryParam('usuario_id', 0),
            (int) $request->getQueryParam('promocion_id', 0),
            $desde,
            $hasta,
            $pag['limite'],
            $pag['offset'],
        ]);

        return Response::ok(array_map(self::canje(...), $filas), Formato::meta($filas, $pag));
    }

    private function buscar(string $codigo, ?int $comercioId): array
    {
        $fila = $this->sp->callSingle('sp_canjes_validar', [$codigo, $comercioId]);
        if ($fila === null) {
            throw new NotFoundException('No encontramos ese código. Revisalo con el visitante.', 'CUPON_INEXISTENTE');
        }
        return $fila;
    }

    public static function codigo(Request $request): string
    {
        $body = $request->getParsedBody();
        $texto = $body['codigo'] ?? ($body['qr_hash'] ?? '');
        $codigo = Codigo::normalizar($texto);
        if ($codigo === '') {
            throw new ValidationException('Ingresá el código del cupón.', ['codigo' => 'Requerido']);
        }
        return $codigo;
    }

    private static function problema(array $f): HttpException
    {
        $detalles = self::cupon($f) + [
            'canjeado_el'  => Formato::fecha($f['canjeado_en'] ?? null),
            'canjeado_por' => ($f['canjeado_por_id'] ?? null) === null ? null : [
                'id'     => (int) $f['canjeado_por_id'],
                'nombre' => $f['canjeado_por_nombre'] ?? '',
            ],
        ];

        return match ($f['problema'] ?? null) {
            'CUPON_OTRO_COMERCIO' => new ForbiddenException('Este cupón es de otro comercio.', 'CUPON_OTRO_COMERCIO'),
            'CUPON_YA_CANJEADO'   => new ConflictException('Este cupón ya se usó.', 'CUPON_YA_CANJEADO', $detalles),
            'CUPON_VENCIDO'       => new ConflictException('Este cupón venció.', 'CUPON_VENCIDO', $detalles),
            'CUPON_ANULADO'       => new ConflictException('Este cupón fue anulado.', 'CUPON_ANULADO', $detalles),
            'COMERCIO_SUSPENDIDO' => new ConflictException('Tu comercio está suspendido: por ahora no se pueden canjear cupones.', 'COMERCIO_SUSPENDIDO'),
            'FUERA_DE_DIA'        => new ConflictException('Este cupón no vale hoy.', 'FUERA_DE_DIA', $detalles),
            'FUERA_DE_HORARIO'    => new ConflictException('Este cupón no vale en este horario.', 'FUERA_DE_HORARIO', $detalles),
            default               => new NotFoundException('No encontramos ese código. Revisalo con el visitante.', 'CUPON_INEXISTENTE'),
        };
    }

    public static function cupon(array $f): array
    {
        return [
            'cupon' => [
                'codigo'     => $f['codigo'] ?? '',
                'estado'     => $f['estado'] ?? '',
                'emitido_el' => Formato::fecha($f['emitido_en'] ?? null),
                'vence_el'   => Formato::fecha($f['vence_en'] ?? null),
            ],
            'promocion' => [
                'id'               => (int) ($f['promocion_id'] ?? 0),
                'titulo'           => $f['titulo'] ?? '',
                'descuento_visual' => $f['descuento_visual'] ?? '',
                'condiciones'      => $f['condiciones'] ?? null,
                'dias_validos'     => Formato::dias($f['dias_validos'] ?? null),
                'horario'          => Formato::horario($f['hora_inicio'] ?? null, $f['hora_fin'] ?? null),
            ],
            'turista' => [
                'nombre' => $f['turista_nombre'] ?? 'Turista',
            ],
        ];
    }

    public static function canje(array $f): array
    {
        return [
            'id'          => (int) ($f['canje_id'] ?? 0),
            'canjeado_el' => Formato::fecha($f['canjeado_en'] ?? null),
            'codigo'      => $f['codigo'] ?? '',
            'promocion'   => [
                'id'               => (int) ($f['promocion_id'] ?? 0),
                'titulo'           => $f['titulo'] ?? '',
                'descuento_visual' => $f['descuento_visual'] ?? '',
            ],
            'comercio' => [
                'id'     => (int) ($f['comercio_id'] ?? 0),
                'nombre' => $f['comercio_nombre'] ?? '',
            ],
            'turista' => [
                'nombre' => $f['turista_nombre'] ?? '',
            ],
            'usuario' => [
                'id'     => (int) ($f['usuario_id'] ?? 0),
                'nombre' => $f['usuario_nombre'] ?? '',
            ],
            'observaciones' => $f['observaciones'] ?? null,
        ];
    }
}
