<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\UnauthorizedException;
use App\Domain\Exceptions\ValidationException;
use App\Services\JwtService;
use App\Support\ApiException;
use App\Support\Formato;

final class LoginController
{
    private const VENTANA_MINUTOS = 15;
    private const MAX_FALLIDOS_USUARIO = 5;
    private const MAX_FALLIDOS_IP = 20;
    private const HORAS_SESION = 12;
    private const HASH_FALSO = '$2y$10$abcdefghijklmnopqrstuu';

    public function __construct(
        private ?StoredProcedureCaller $sp = null,
        private ?JwtService $jwt = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
        $this->jwt ??= new JwtService();
    }

    public function ingresar(Request $request): Response
    {
        $b = $request->getParsedBody();
        $usuario = strtolower(trim((string) ($b['usuario'] ?? '')));
        $password = (string) ($b['password'] ?? '');

        if ($usuario === '' || $password === '') {
            throw new ValidationException('Completá usuario y contraseña.', array_filter([
                'usuario'  => $usuario === '' ? 'Requerido' : null,
                'password' => $password === '' ? 'Requerido' : null,
            ]));
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        try {
            $f = $this->sp->callSingle('sp_login_intentos_fallidos', [$usuario, $ip, self::VENTANA_MINUTOS]);
            if ($f !== null && ((int) ($f['fallidos_usuario'] ?? 0) >= self::MAX_FALLIDOS_USUARIO || (int) ($f['fallidos_ip'] ?? 0) >= self::MAX_FALLIDOS_IP)) {
                throw new ApiException(429, 'Demasiados intentos. Esperá 15 minutos y probá de nuevo.', 'DEMASIADOS_INTENTOS');
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Ignorar si tabla de intentos aún no existe en prueba
        }

        $u = $this->sp->callSingle('sp_usuarios_obtener_por_nombreu', [$usuario]);
        $claveOk = password_verify($password, (string) ($u['password_hash'] ?? self::HASH_FALSO));

        if ($u === null || !$claveOk) {
            try {
                $this->sp->call('sp_login_intento_registrar', [$usuario, $ip, 0, null]);
            } catch (\Throwable) {}
            throw new UnauthorizedException('Usuario o contraseña incorrectos.', 'CREDENCIALES_INVALIDAS');
        }

        if (($u['estado'] ?? '') !== 'ACTIVO') {
            try {
                $this->sp->call('sp_login_intento_registrar', [$usuario, $ip, 0, null]);
            } catch (\Throwable) {}
            throw new ForbiddenException('Tu usuario está bloqueado. Pedile al Ente que lo habilite.', 'USUARIO_BLOQUEADO');
        }

        $usuarioId = (int) $u['usuario_id'];
        try {
            $this->sp->call('sp_login_intento_registrar', [$usuario, $ip, 1, $usuarioId]);
        } catch (\Throwable) {}

        if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $this->sp->call('sp_usuarios_cambiar_password', [$usuarioId, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (\Throwable) {}
        }

        $sesion = $this->sp->callSingle('sp_usuarios_sesion', [$usuarioId]);
        $expira = time() + self::HORAS_SESION * 3600;
        $secret = (string) Env::get('JWT_SECRET', '');

        $token = $this->jwt->encode([
            'sub'         => 'usr_' . $usuarioId,
            'usuario_id'  => $usuarioId,
            'rol'         => $sesion['rol'] ?? 'empleado',
            'comercio_id' => ($sesion['comercio_id'] ?? null) === null ? null : (int) $sesion['comercio_id'],
            'iat'         => time(),
            'exp'         => $expira,
        ], $secret);

        return Response::ok([
            'token'     => $token,
            'expira_el' => Formato::fecha(date('Y-m-d H:i:s', $expira)),
            'usuario'   => self::usuario($sesion ?? $u),
        ]);
    }

    public function sesion(Request $request): Response
    {
        $usuarioId = (int) ($request->getAttribute('user')['usuario_id'] ?? 0);
        $sesion = $this->sp->callSingle('sp_usuarios_sesion', [$usuarioId]);
        return Response::ok(self::usuario($sesion));
    }

    public function cambiarPassword(Request $request): Response
    {
        $b = $request->getParsedBody();
        $actual = (string) ($b['actual'] ?? '');
        $nueva = (string) ($b['nueva'] ?? '');

        if (mb_strlen($nueva) < 10) {
            throw new ValidationException('La contraseña nueva tiene que tener al menos 10 caracteres.', ['nueva' => 'Mínimo 10 caracteres']);
        }

        $usuarioId = (int) ($request->getAttribute('user')['usuario_id'] ?? 0);
        $sesion = $this->sp->callSingle('sp_usuarios_sesion', [$usuarioId]);
        $nombreu = (string) ($sesion['nombreu'] ?? '');
        $u = $this->sp->callSingle('sp_usuarios_obtener_por_nombreu', [$nombreu]);

        if (!password_verify($actual, (string) ($u['password_hash'] ?? ''))) {
            throw new ValidationException('La contraseña actual no es correcta.', ['actual' => 'Incorrecta'], 'PASSWORD_ACTUAL_INCORRECTA');
        }

        $this->sp->call('sp_usuarios_cambiar_password', [$usuarioId, password_hash($nueva, PASSWORD_DEFAULT)]);
        return Response::ok(['mensaje' => 'Cambiaste tu contraseña.']);
    }

    public static function usuario(?array $sesion): array
    {
        if ($sesion === null) {
            return [];
        }
        return [
            'id'            => (int) ($sesion['usuario_id'] ?? 0),
            'usuario'       => $sesion['nombreu'] ?? '',
            'nombre'        => $sesion['nombre'] ?? ($sesion['nombreu'] ?? ''),
            'email'         => $sesion['email'] ?? null,
            'rol'           => $sesion['rol'] ?? 'empleado',
            'comercio'      => empty($sesion['comercio_id']) ? null : [
                'id'     => (int) $sesion['comercio_id'],
                'nombre' => $sesion['comercio_nombre'] ?? '',
                'estado' => $sesion['comercio_estado'] ?? 'AUTORIZADO',
            ],
            'ultimo_acceso' => Formato::fecha($sesion['ultimo_acceso'] ?? null),
        ];
    }
}
