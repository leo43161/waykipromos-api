<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ConflictException;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\NotFoundException;
use App\Domain\Exceptions\ValidationException;
use App\Support\Acceso;
use App\Support\Actividad;
use App\Support\Formato;

final class UsuariosPanelController
{
    private const ADMINISTRA = [
        'encargado'  => ['empleado'],
        'admin_ente' => ['encargado', 'empleado'],
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

        $filas = $this->sp->call('sp_usuarios_listar', [
            0,
            Acceso::comercio($user, $request->getQueryParam('comercio_id')),
            ($user['rol'] ?? '') === 'encargado' ? 'empleado' : (string) $request->getQueryParam('rol', ''),
            strtoupper((string) $request->getQueryParam('estado', '')),
            mb_substr(trim((string) $request->getQueryParam('q', '')), 0, 100),
            $pag['limite'],
            $pag['offset'],
        ]);

        return Response::ok(array_map(self::usuario(...), $filas), Formato::meta($filas, $pag));
    }

    public function crear(Request $request): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $b = $request->getParsedBody();
        $rol = ($user['rol'] ?? '') === 'encargado' ? 'empleado' : (string) ($b['rol'] ?? '');

        $this->exigirAdministra($user, $rol);

        $comercioId = $rol === 'admin_ente' || $rol === 'superadmin' ? null
            : (Acceso::comercio($user, $b['comercio_id'] ?? null) ?? throw new ValidationException('Elegí el comercio.', ['comercio_id' => 'Requerido']));

        $usuario = strtolower(trim((string) ($b['usuario'] ?? '')));
        $nombre = trim((string) ($b['nombre'] ?? ''));

        $err = array_filter([
            'usuario'  => preg_match('/^[a-z0-9._-]{3,100}$/', $usuario) ? null : 'De 3 a 100 caracteres: letras, números, punto, guion',
            'nombre'   => mb_strlen($nombre) >= 3 ? null : 'Requerido',
            'email'    => empty($b['email']) || filter_var($b['email'], FILTER_VALIDATE_EMAIL) ? null : 'Email inválido',
            'password' => !isset($b['password']) || mb_strlen((string) $b['password']) >= 10 ? null : 'Mínimo 10 caracteres',
        ]);

        if ($err !== []) {
            throw new ValidationException('Revisá los datos del usuario.', $err);
        }

        $temporal = isset($b['password']) ? null : self::claveTemporal();

        try {
            $fila = $this->sp->callSingle('sp_usuarios_alta', [
                $usuario,
                password_hash($temporal ?? (string) $b['password'], PASSWORD_DEFAULT),
                $nombre,
                (string) ($b['email'] ?? ''),
                (string) ($b['telefono'] ?? ''),
                $comercioId,
                $rol,
                (int) ($user['usuario_id'] ?? 0),
            ]);
        } catch (ConflictException) {
            throw new ConflictException('Ese nombre de usuario ya existe. Probá con otro.', 'USUARIO_REPETIDO', ['usuario' => 'Ya existe']);
        }

        $id = (int) ($fila['usuario_id'] ?? 0);
        Actividad::registrar(
            $request,
            'USUARIO_CREADO',
            sprintf('%s creó a %s (%s)', $user['nombre'] ?? 'Usuario', $nombre, $rol),
            $comercioId,
            'usuario',
            $id
        );

        $nuevo = self::usuario($this->sp->callSingle('sp_usuarios_listar', [$id, 0, '', '', '', 1, 0]));

        return Response::ok($temporal === null ? $nuevo : $nuevo + ['password_temporal' => $temporal], null, 201);
    }

    public function editar(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($user, (int) ($params['id'] ?? 0));
        $b = $request->getParsedBody();
        $rol = (string) ($b['rol'] ?? '');

        if ($rol !== '' && $rol !== $actual['rol']) {
            $this->exigirAdministra($user, $rol);
        }

        $this->sp->call('sp_usuarios_editar', [
            (int) $actual['usuario_id'],
            trim((string) ($b['nombre'] ?? $actual['nombre'])),
            (string) ($b['email'] ?? $actual['email'] ?? ''),
            (string) ($b['telefono'] ?? $actual['telefono'] ?? ''),
            $rol,
            (int) ($user['usuario_id'] ?? 0),
        ]);

        Actividad::registrar(
            $request,
            'USUARIO_EDITADO',
            sprintf('%s editó a %s', $user['nombre'] ?? 'Usuario', $actual['nombre'] ?? $actual['nombreu']),
            $actual['comercio_id'] === null ? null : (int) $actual['comercio_id'],
            'usuario',
            (int) $actual['usuario_id']
        );

        return Response::ok(self::usuario($this->buscar($user, (int) $actual['usuario_id'])));
    }

    public function cambiarEstado(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($user, (int) ($params['id'] ?? 0));
        $estado = strtoupper((string) ($request->getParsedBody()['estado'] ?? ''));

        if (!in_array($estado, ['ACTIVO', 'BLOQUEADO', 'BAJA'], true)) {
            throw new ValidationException('Estado inválido.', ['estado' => 'ACTIVO, BLOQUEADO o BAJA']);
        }

        $this->sp->call('sp_usuarios_cambiar_estado', [(int) $actual['usuario_id'], $estado]);

        Actividad::registrar(
            $request,
            'USUARIO_ESTADO',
            sprintf('%s pasó a %s a %s', $user['nombre'] ?? 'Usuario', $actual['nombre'] ?? $actual['nombreu'], $estado),
            $actual['comercio_id'] === null ? null : (int) $actual['comercio_id'],
            'usuario',
            (int) $actual['usuario_id']
        );

        return Response::ok(self::usuario($this->buscar($user, (int) $actual['usuario_id'])));
    }

    public function nuevaPassword(Request $request, array $params): Response
    {
        $user = $request->getAttribute('user') ?? [];
        $actual = $this->buscar($user, (int) ($params['id'] ?? 0));
        $temporal = self::claveTemporal();

        $this->sp->call('sp_usuarios_cambiar_password', [(int) $actual['usuario_id'], password_hash($temporal, PASSWORD_DEFAULT)]);

        Actividad::registrar(
            $request,
            'USUARIO_PASSWORD',
            sprintf('%s generó una contraseña nueva para %s', $user['nombre'] ?? 'Usuario', $actual['nombre'] ?? $actual['nombreu']),
            $actual['comercio_id'] === null ? null : (int) $actual['comercio_id'],
            'usuario',
            (int) $actual['usuario_id']
        );

        return Response::ok(['password_temporal' => $temporal]);
    }

    private function buscar(array $user, int $id): array
    {
        $fila = $this->sp->callSingle('sp_usuarios_listar', [$id, 0, '', '', '', 1, 0]);
        $puede = $fila !== null && $id !== (int) ($user['usuario_id'] ?? 0)
            && (($user['rol'] ?? '') === 'superadmin' || in_array($fila['rol'] ?? '', self::ADMINISTRA[$user['rol'] ?? ''] ?? [], true))
            && (Acceso::esEnte($user) || (int) ($fila['comercio_id'] ?? 0) === (int) ($user['comercio_id'] ?? 0));

        if (!$puede) {
            throw new NotFoundException('No existe ese usuario o no lo podés administrar.', 'NO_ENCONTRADO');
        }
        return $fila;
    }

    private function exigirAdministra(array $user, string $rol): void
    {
        $permitidos = ($user['rol'] ?? '') === 'superadmin'
            ? ['superadmin', 'admin_ente', 'encargado', 'empleado']
            : (self::ADMINISTRA[$user['rol'] ?? ''] ?? []);

        if (!in_array($rol, $permitidos, true)) {
            throw new ForbiddenException('No podés asignar ese rol.', 'ROL_NO_AUTORIZADO', ['permitidos' => $permitidos]);
        }
    }

    private static function claveTemporal(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $res = '';
        for ($i = 0; $i < 12; $i++) {
            $res .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $res;
    }

    public static function usuario(array $f): array
    {
        return [
            'id'            => (int) ($f['usuario_id'] ?? 0),
            'usuario'       => $f['nombreu'] ?? '',
            'nombre'        => $f['nombre'] ?? '',
            'email'         => $f['email'] ?? null,
            'telefono'      => $f['telefono'] ?? null,
            'rol'           => $f['rol'] ?? '',
            'estado'        => $f['estado'] ?? '',
            'comercio'      => empty($f['comercio_id']) ? null : [
                'id'     => (int) $f['comercio_id'],
                'nombre' => $f['comercio_nombre'] ?? '',
            ],
            'ultimo_acceso' => Formato::fecha($f['ultimo_acceso'] ?? null),
            'creado_el'     => Formato::fecha($f['creado_en'] ?? null),
        ];
    }
}
