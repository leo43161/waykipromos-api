<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ValidationException;
use App\Support\Acceso;
use App\Support\Actividad;
use App\Support\Formato;

final class ComerciosController
{
    public function __construct(
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function listar(Request $request): Response
    {
        $pag = Formato::paginacion($request, 100);
        $filas = [];
        try {
            $filas = $this->sp->call('sp_comercios_listar', [
                0,
                mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100),
                strtoupper((string) $request->getQueryParam('estado', '')),
                (int) $request->getQueryParam('categoria_id', 0),
                (int) $request->getQueryParam('localidad_id', 0),
                $pag['limite'],
                $pag['offset'],
            ]);
        } catch (\Throwable) {
            try {
                $filas = $this->sp->call('sp_comercios_obtener', [0]);
            } catch (\Throwable) {
                $filas = [];
            }
        }
        return Response::ok(array_map(self::comercio(...), $filas), Formato::meta($filas, $pag));
    }

    public function ver(Request $request, array $params): Response
    {
        return Response::ok(self::comercio($this->buscar($request, (int) ($params['id'] ?? 0))));
    }

    public function crear(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $d = self::datos($request->getParsedBody(), null);

        $id = (int) $this->sp->callWithOut('sp_comercios_crear', [
            $d['nombre'],
            $d['categoria_id'],
            $d['direccion'],
            $d['localidad_id'],
            $d['email_contacto'],
            $d['telefono_contacto'],
            null,
            null,
        ]);

        $this->sp->call('sp_comercios_cambiar_estado', [$id, 'AUTORIZADO', null]);
        Actividad::registrar($request, 'COMERCIO_CREADO', sprintf('%s dio de alta a %s', $user['nombre'] ?? 'Usuario', $d['nombre']), $id, 'comercio', $id);

        return Response::ok(self::comercio($this->buscar($request, $id)), null, 201);
    }

    public function editar(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($request, (int) ($params['id'] ?? 0));
        $b = $request->getParsedBody();

        if (!Acceso::esEnte($user)) {
            $b = array_intersect_key($b, array_flip(['direccion', 'email_contacto', 'telefono_contacto']));
        }

        $d = self::datos($b, $actual);

        $this->sp->call('sp_comercios_actualizar', [
            (int) $actual['comercio_id'],
            $d['nombre'],
            $d['categoria_id'],
            $d['direccion'],
            $d['localidad_id'],
            $d['email_contacto'],
            $d['telefono_contacto'],
            $actual['estado_autorizacion'],
            $actual['observaciones_autorizacion'],
            $actual['autorizado_en'],
            1,
            0,
        ]);

        Actividad::registrar(
            $request,
            'COMERCIO_EDITADO',
            sprintf('%s editó %s', $user['nombre'] ?? 'Usuario', $d['nombre']),
            (int) $actual['comercio_id'],
            'comercio',
            (int) $actual['comercio_id']
        );

        return Response::ok(self::comercio($this->buscar($request, (int) $actual['comercio_id'])));
    }

    public function cambiarEstado(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($request, (int) ($params['id'] ?? 0));
        $b = $request->getParsedBody();
        $estado = strtoupper((string) ($b['estado'] ?? ''));
        $motivo = trim((string) ($b['motivo'] ?? ''));

        if (!in_array($estado, ['AUTORIZADO', 'SUSPENDIDO'], true)) {
            throw new ValidationException('Estado inválido.', ['estado' => 'AUTORIZADO o SUSPENDIDO']);
        }
        if ($estado === 'SUSPENDIDO' && mb_strlen($motivo) < 5) {
            throw new ValidationException('Contá por qué se suspende.', ['motivo' => 'Requerido al suspender']);
        }

        try {
            $this->sp->call('sp_comercios_cambiar_estado', [(int) $actual['comercio_id'], $estado, $motivo]);
        } catch (\Throwable) {
            try {
                $stmt = \App\Database\Database::getConnection()->prepare("UPDATE comercios SET estado_autorizacion = :e, observaciones_autorizacion = :o WHERE comercio_id = :id");
                $stmt->execute(['e' => $estado, 'o' => $motivo, 'id' => (int) $actual['comercio_id']]);
            } catch (\Throwable) {}
        }

        Actividad::registrar(
            $request,
            'COMERCIO_ESTADO',
            sprintf('%s pasó %s a %s%s', $user['nombre'] ?? 'Usuario', $actual['nombre'], $estado, $motivo ? " ($motivo)" : ''),
            (int) $actual['comercio_id'],
            'comercio',
            (int) $actual['comercio_id']
        );

        return Response::ok(self::comercio($this->buscar($request, (int) $actual['comercio_id'])));
    }

    private function buscar(Request $request, int $id): array
    {
        $user = $request->getAttribute('user') ?? [];
        $fila = null;
        try {
            $fila = $this->sp->callSingle('sp_comercios_listar', [$id, '', '', 0, 0, 1, 0]);
        } catch (\Throwable) {
            try {
                $fila = $this->sp->callSingle('sp_comercios_obtener', [$id]);
            } catch (\Throwable) {}
        }
        return Acceso::delComercio($user, $fila, 'No existe ese comercio.');
    }

    public static function comercio(array $f): array
    {
        return [
            'id'                => (int) $f['comercio_id'],
            'nombre'            => $f['nombre'],
            'categoria'         => ['id' => (int) $f['categoria_id'], 'nombre' => $f['categoria_nombre']],
            'direccion'         => $f['direccion'],
            'localidad'         => ['id' => (int) $f['localidad_id'], 'nombre' => $f['localidad_nombre']],
            'circuito'          => empty($f['circuito_id']) ? null : ['id' => (int) $f['circuito_id'], 'nombre' => $f['circuito_nombre']],
            'email_contacto'    => $f['email_contacto'] ?? null,
            'telefono_contacto' => $f['telefono_contacto'] ?? null,
            'logo_url'          => Formato::url($f['logo_url'] ?? null),
            'estado'            => $f['estado_autorizacion'],
            'motivo_estado'     => $f['observaciones_autorizacion'] ?? null,
            'autorizado_el'     => Formato::fecha($f['autorizado_en'] ?? null),
            'creado_el'         => Formato::fecha($f['creado_en'] ?? null),
            'numeros'           => [
                'promociones_publicadas' => (int) ($f['promociones_publicadas'] ?? 0),
                'usuarios_activos'       => (int) ($f['usuarios_activos'] ?? 0),
                'cupones_emitidos'       => (int) ($f['cupones_emitidos'] ?? 0),
                'cupones_canjeados'      => (int) ($f['cupones_canjeados'] ?? 0),
            ],
        ];
    }

    private static function datos(array $b, ?array $actual): array
    {
        $v = static fn (string $campo) => trim((string) (array_key_exists($campo, $b) ? $b[$campo] : ($actual[$campo] ?? '')));
        $d = [
            'nombre'            => $v('nombre'),
            'categoria_id'      => (int) (array_key_exists('categoria_id', $b) ? $b['categoria_id'] : ($actual['categoria_id'] ?? 0)),
            'direccion'         => $v('direccion'),
            'localidad_id'      => (int) (array_key_exists('localidad_id', $b) ? $b['localidad_id'] : ($actual['localidad_id'] ?? 0)),
            'email_contacto'    => $v('email_contacto') ?: null,
            'telefono_contacto' => $v('telefono_contacto') ?: null,
        ];

        $err = array_filter([
            'nombre'         => mb_strlen($d['nombre']) >= 2 && mb_strlen($d['nombre']) <= 180 ? null : 'Entre 2 y 180 caracteres',
            'categoria_id'   => $d['categoria_id'] > 0 ? null : 'Requerido',
            'direccion'      => $d['direccion'] !== '' ? null : 'Requerido',
            'localidad_id'   => $d['localidad_id'] > 0 ? null : 'Requerido',
            'email_contacto' => $d['email_contacto'] === null || filter_var($d['email_contacto'], FILTER_VALIDATE_EMAIL) ? null : 'Email inválido',
        ]);

        if ($err !== []) {
            throw new ValidationException('Revisá los datos del comercio.', $err);
        }

        return $d;
    }
}
