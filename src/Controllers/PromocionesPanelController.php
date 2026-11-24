<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Support\Acceso;
use App\Support\Actividad;
use App\Support\Formato;

final class PromocionesPanelController
{
    private const DIAS = ['LUN', 'MAR', 'MIE', 'JUE', 'VIE', 'SAB', 'DOM'];

    private const PASOS = [
        'BORRADOR'   => ['PUBLICADA', 'BAJA'],
        'PUBLICADA'  => ['PAUSADA', 'FINALIZADA', 'BAJA'],
        'PAUSADA'    => ['PUBLICADA', 'FINALIZADA', 'BAJA'],
        'FINALIZADA' => ['BAJA'],
        'BAJA'       => [],
    ];

    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function listar(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $pag = Formato::paginacion($request, 100);

        $filas = $this->sp->call('sp_promociones_gestion', [
            0,
            Acceso::comercio($user, $request->getQueryParam('comercio_id')),
            strtoupper((string) $request->getQueryParam('estado', '')),
            (int) $request->getQueryParam('categoria_id', 0),
            mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100),
            $pag['limite'],
            $pag['offset'],
        ]);

        return Response::ok(array_map(self::promocion(...), $filas), Formato::meta($filas, $pag));
    }

    public function ver(Request $request, array $params): Response
    {
        return Response::ok(self::promocion($this->buscar($request, (int) ($params['id'] ?? 0))));
    }

    public function crear(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $b = $request->getParsedBody();

        $comercioId = Acceso::comercio($user, $b['comercio_id'] ?? null)
            ?? throw new ValidationException('Elegí el comercio.', ['comercio_id' => 'Requerido']);

        $comercio = $this->sp->callSingle('sp_comercios_listar', [$comercioId, '', '', 0, 0, 1, 0])
            ?? throw new ValidationException('Ese comercio no existe.', ['comercio_id' => 'Inválido']);

        $d = $this->datos($b, null, $user);

        $id = (int) $this->sp->callWithOut('sp_promociones_crear', [
            $comercioId,
            $d['titulo'],
            $d['descripcion'],
            $d['modalidad_id'],
            $d['modalidad_personalizada'],
            $d['fecha_desde'],
            $d['fecha_hasta'],
            $d['dias_validos'],
            $d['hora_desde'],
            $d['hora_hasta'],
            $d['condiciones'],
            $d['cupos_totales'],
            $d['cupos_totales'],
            (int) ($user['usuario_id'] ?? 0),
            null, // circuito sale de la localidad del comercio
            $d['categoria_id'] ?? (int) $comercio['categoria_id'],
            $d['orden'] ?? 100,
        ]);

        if (strtoupper((string) ($b['estado'] ?? 'BORRADOR')) === 'PUBLICADA') {
            $this->pasarA($request, $this->buscar($request, $id), 'PUBLICADA');
        }

        Actividad::registrar(
            $request,
            'PROMOCION_CREADA',
            sprintf('%s creó "%s"', $user['nombre'] ?? 'Usuario', $d['titulo']),
            $comercioId,
            'promocion',
            $id
        );

        return Response::ok(self::promocion($this->buscar($request, $id)), null, 201);
    }

    public function editar(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($request, (int) ($params['id'] ?? 0));

        if (in_array($actual['estado'], ['FINALIZADA', 'BAJA'], true)) {
            throw new ConflictException('Una promoción finalizada o dada de baja no se puede editar.', 'PROMOCION_CERRADA');
        }

        $d = $this->datos($request->getParsedBody(), $actual, $user);

        if ($d['cupos_totales'] < (int) ($actual['emitidos'] ?? 0)) {
            throw new ValidationException('No podés bajar los cupos por debajo de los ya entregados.', [
                'cupos_totales' => 'Mínimo ' . ($actual['emitidos'] ?? 0),
            ]);
        }

        $this->sp->call('sp_promociones_actualizar', [
            (int) $actual['promocion_id'],
            (int) $actual['comercio_id'],
            $d['titulo'],
            $d['descripcion'],
            $d['modalidad_id'],
            $d['modalidad_personalizada'],
            $d['fecha_desde'],
            $d['fecha_hasta'],
            $d['dias_validos'],
            $d['hora_desde'],
            $d['hora_hasta'],
            $d['condiciones'],
            $d['cupos_totales'],
            $d['cupos_totales'] - (int) ($actual['emitidos'] ?? 0),
            $actual['estado'],
            (int) $actual['creado_por'],
            null,
            $d['categoria_id'] ?? (int) $actual['categoria_id'],
            1,
            0,
            $d['orden'] ?? (int) ($actual['orden'] ?? 100),
        ]);

        Actividad::registrar(
            $request,
            'PROMOCION_EDITADA',
            sprintf('%s editó "%s"', $user['nombre'] ?? 'Usuario', $d['titulo']),
            (int) $actual['comercio_id'],
            'promocion',
            (int) $actual['promocion_id']
        );

        return Response::ok(self::promocion($this->buscar($request, (int) $actual['promocion_id'])));
    }

    public function cambiarEstado(Request $request, array $params): Response
    {
        $actual = $this->buscar($request, (int) ($params['id'] ?? 0));
        $b = $request->getParsedBody();
        $this->pasarA($request, $actual, strtoupper((string) ($b['estado'] ?? '')), trim((string) ($b['motivo'] ?? '')));
        return Response::ok(self::promocion($this->buscar($request, (int) $actual['promocion_id'])));
    }

    private function buscar(Request $request, int $id): array
    {
        $user = $request->getAttribute('user') ?? [];
        $fila = $this->sp->callSingle('sp_promociones_gestion', [$id, 0, '', 0, '', 1, 0]);
        return Acceso::delComercio($user, $fila, 'No existe esa promoción.');
    }

    private function pasarA(Request $request, array $actual, string $estado, string $motivo = ''): void
    {
        $permitidos = self::PASOS[$actual['estado']] ?? [];
        if (!in_array($estado, $permitidos, true)) {
            throw new ConflictException(
                sprintf('Una promoción %s no puede pasar a %s.', $actual['estado'], $estado ?: '(vacío)'),
                'TRANSICION_INVALIDA',
                ['permitidos' => $permitidos]
            );
        }

        if ($estado === 'PUBLICADA') {
            $motivos = array_filter([
                ($actual['comercio_estado'] ?? '') !== 'AUTORIZADO' ? 'El comercio no está autorizado.' : null,
                ($actual['fecha_fin'] ?? '') < date('Y-m-d') ? 'La fecha de fin ya pasó.' : null,
                (int) ($actual['stock_disponible'] ?? 0) <= 0 ? 'No le quedan cupos.' : null,
            ]);
            if (!empty($motivos)) {
                throw new ConflictException('Todavía no se puede publicar.', 'NO_SE_PUEDE_PUBLICAR', ['motivos' => array_values($motivos)]);
            }
        }

        $this->sp->call('sp_promociones_cambiar_estado', [(int) $actual['promocion_id'], $estado]);
        $user = $request->getAttribute('user') ?? [];

        Actividad::registrar(
            $request,
            'PROMOCION_ESTADO',
            sprintf('%s pasó "%s" a %s%s', $user['nombre'] ?? 'Usuario', $actual['titulo'] ?? '', $estado, $motivo !== '' ? " ($motivo)" : ''),
            (int) $actual['comercio_id'],
            'promocion',
            (int) $actual['promocion_id']
        );
    }

    private function datos(array $b, ?array $actual, array $user): array
    {
        $v = static fn (string $campo, mixed $siFalta) => array_key_exists($campo, $b) ? $b[$campo] : $siFalta;
        $err = [];
        $d = [
            'titulo'                  => trim((string) $v('titulo', $actual['titulo'] ?? '')),
            'descripcion'             => trim((string) $v('descripcion', $actual['descripcion'] ?? '')) ?: null,
            'condiciones'             => trim((string) $v('condiciones', $actual['condiciones'] ?? '')) ?: null,
            'modalidad_id'            => (int) $v('modalidad_id', $actual['modalidad_id'] ?? 0),
            'modalidad_personalizada' => trim((string) $v('modalidad_personalizada', $actual['modalidad_personalizada'] ?? '')) ?: null,
            'fecha_desde'             => (string) $v('fecha_desde', $actual['fecha_inicio'] ?? ''),
            'fecha_hasta'             => (string) $v('fecha_hasta', $actual['fecha_fin'] ?? ''),
            'cupos_totales'           => (int) $v('cupos_totales', $actual['stock_total'] ?? 0),
            'categoria_id'            => array_key_exists('categoria_id', $b) ? (int) $b['categoria_id'] : null,
            'orden'                   => null,
        ];

        $dias = $v('dias_validos', Formato::dias($actual['dias_validos'] ?? null));
        $horario = $v('horario', Formato::horario($actual['hora_inicio'] ?? null, $actual['hora_fin'] ?? null));

        if (mb_strlen($d['titulo']) < 3 || mb_strlen($d['titulo']) > 180) {
            $err['titulo'] = 'Entre 3 y 180 caracteres';
        }
        if (mb_strlen((string) $d['descripcion']) > 2000) {
            $err['descripcion'] = 'Hasta 2000 caracteres';
        }
        if (mb_strlen((string) $d['condiciones']) > 2000) {
            $err['condiciones'] = 'Hasta 2000 caracteres';
        }

        $modalidad = $d['modalidad_id'] > 0 ? $this->sp->callSingle('sp_modalidades_descuento_obtener', [$d['modalidad_id']]) : null;
        if ($modalidad === null) {
            $err['modalidad_id'] = 'Elegí una modalidad';
        } elseif (($modalidad['tipo'] ?? '') === 'PERSONALIZADO' && $d['modalidad_personalizada'] === null) {
            $err['modalidad_personalizada'] = 'Escribí el beneficio (ej. "Postre de regalo")';
        } elseif (($modalidad['tipo'] ?? '') !== 'PERSONALIZADO') {
            $d['modalidad_personalizada'] = null;
        }

        $fecha = static fn (string $f): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 && strtotime($f) !== false;
        if (!$fecha($d['fecha_desde'])) {
            $err['fecha_desde'] = 'Formato yyyy-mm-dd';
        }
        if (!$fecha($d['fecha_hasta'])) {
            $err['fecha_hasta'] = 'Formato yyyy-mm-dd';
        } elseif ($d['fecha_hasta'] < $d['fecha_desde']) {
            $err['fecha_hasta'] = 'Tiene que ser igual o posterior a la de inicio';
        } elseif ($actual === null && $d['fecha_hasta'] < date('Y-m-d')) {
            $err['fecha_hasta'] = 'Ya pasó';
        }

        if ($d['cupos_totales'] < 1 || $d['cupos_totales'] > 100000) {
            $err['cupos_totales'] = 'Entre 1 y 100000';
        }

        if ($dias === null || $dias === []) {
            $d['dias_validos'] = null;
        } elseif (!is_array($dias) || array_diff($dias, self::DIAS) !== []) {
            $err['dias_validos'] = 'Lista de LUN, MAR, MIE, JUE, VIE, SAB, DOM';
        } else {
            $d['dias_validos'] = implode(',', array_values(array_intersect(self::DIAS, $dias)));
        }

        $hora = static fn (mixed $h): bool => is_string($h) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) === 1;
        if ($horario === null) {
            $d['hora_desde'] = $d['hora_hasta'] = null;
        } elseif (!is_array($horario) || !$hora($horario['desde'] ?? null) || !$hora($horario['hasta'] ?? null)) {
            $err['horario'] = 'Objeto {desde: "HH:MM", hasta: "HH:MM"} o null';
        } else {
            [$d['hora_desde'], $d['hora_hasta']] = [$horario['desde'] . ':00', $horario['hasta'] . ':00'];
        }

        if (array_key_exists('destacada', $b)) {
            Acceso::exigirEnte($user);
            $d['orden'] = $b['destacada'] ? 0 : 100;
        }

        if ($err !== []) {
            throw new ValidationException('Revisá los datos de la promoción.', $err);
        }

        return $d;
    }

    public static function promocion(array $r): array
    {
        return Formato::promocion($r) + [
            'estado'                  => $r['estado'] ?? '',
            'publica'                 => (bool) ($r['publica'] ?? false),
            'descripcion'             => $r['descripcion'] ?? null,
            'condiciones'             => $r['condiciones'] ?? null,
            'modalidad'               => [
                'id'    => (int) ($r['modalidad_id'] ?? 0),
                'nombre'=> $r['modalidad_nombre'] ?? '',
                'tipo'  => $r['modalidad_tipo'] ?? '',
                'valor' => ($r['modalidad_valor'] ?? null) === null ? null : (float) $r['modalidad_valor'],
            ],
            'modalidad_personalizada' => $r['modalidad_personalizada'] ?? null,
            'cupos_emitidos'          => (int) ($r['emitidos'] ?? 0),
            'cupos_canjeados'         => (int) ($r['canjeados'] ?? 0),
            'creado_por'              => [
                'id'     => (int) ($r['creado_por'] ?? 0),
                'nombre' => $r['creado_por_nombre'] ?? ($r['creado_por_usuario'] ?? 'Admin'),
            ],
            'creado_el'               => Formato::fecha($r['creado_en'] ?? null),
            'actualizado_el'          => Formato::fecha($r['actualizado_en'] ?? null),
        ];
    }
}
