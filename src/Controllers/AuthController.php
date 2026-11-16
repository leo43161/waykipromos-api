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
use App\Services\GoogleAuthService;
use App\Services\JwtService;

class AuthController
{
    public function __construct(
        private StoredProcedureCaller $sp = new StoredProcedureCaller(),
        private JwtService $jwt = new JwtService(),
        private GoogleAuthService $googleAuth = new GoogleAuthService()
    ) {}

    /**
     * POST /api/v1/auth/google
     * Autenticación federada para turistas móviles.
     */
    public function googleLogin(Request $request): Response
    {
        $body = $request->getParsedBody();
        $idToken = (string) ($body['id_token'] ?? $body['credential'] ?? '');

        if ($idToken === '') {
            throw new ValidationException('El parámetro id_token es obligatorio', ['campo' => 'id_token']);
        }

        $googleProfile = $this->googleAuth->verifyIdToken($idToken);

        $googleSub = (string) $googleProfile['sub'];
        $email     = (string) ($googleProfile['email'] ?? '');
        $nombre    = (string) ($googleProfile['name'] ?? $googleProfile['given_name'] ?? 'Turista');
        $apellido  = (string) ($googleProfile['family_name'] ?? '');
        $fotoUrl   = (string) ($googleProfile['picture'] ?? '');

        // 1. Buscar o crear turista en la base huakipromo
        $turista = $this->sp->callSingle('sp_turistas_obtener_por_google_sub', [$googleSub]);

        if ($turista === null) {
            // Alta de nuevo turista
            $nuevoId = $this->sp->callWithOut('sp_turistas_crear', [
                $googleSub,
                $email,
                1, // email_verified
                $nombre . ($apellido !== '' ? ' ' . $apellido : ''),
                $fotoUrl,
                $nombre,
                $apellido,
            ]);

            $turistaId = (int) $nuevoId;
        } else {
            $turistaId = (int) ($turista['turistas_id'] ?? $turista['id']);
        }

        // 2. Generar token JWT para la sesión del turista
        $secret = (string) Env::get('JWT_SECRET', 'secret_key_waykipromo_2026_at_least_32_chars');
        $payload = [
            'sub'         => 'tur_' . $turistaId,
            'turistas_id' => $turistaId,
            'email'       => $email,
            'nombre'      => $nombre,
            'rol'         => 'turista',
            'comercio_id' => null,
            'iat'         => time(),
            'exp'         => time() + (86400 * 30), // 30 días de vigencia móvil
        ];

        $token = $this->jwt->encode($payload, $secret);

        return Response::ok([
            'token'   => $token,
            'turista' => [
                'id'       => $turistaId,
                'email'    => $email,
                'nombre'   => $nombre,
                'apellido' => $apellido,
                'foto_url' => $fotoUrl,
                'rol'      => 'turista',
            ],
        ]);
    }

    /**
     * POST /api/v1/auth/login
     * Autenticación con credenciales para personal comercial (empleado/encargado) y Ente de Turismo.
     */
    public function emailLogin(Request $request): Response
    {
        $body = $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? $body['nombreu'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new ValidationException('Email y contraseña son obligatorios', [
                'email' => $email === '' ? 'Requerido' : null,
                'password' => $password === '' ? 'Requerido' : null,
            ]);
        }

        // 1. Obtener usuario por email/nombreu
        $usuario = $this->sp->callSingle('sp_usuarios_obtener_por_nombreu', [$email]);

        if ($usuario === null) {
            throw new UnauthorizedException('Credenciales inválidas', 'CREDENCIALES_INVALIDAS');
        }

        // 2. Verificar estado operativo
        $estado = strtoupper((string) ($usuario['estado'] ?? ''));
        if ($estado !== 'ACTIVO') {
            throw new ForbiddenException("El usuario se encuentra en estado '{$estado}'. Contacte al administrador.", 'USUARIO_INACTIVO');
        }

        // 3. Verificar hash de contraseña
        $passwordHash = (string) ($usuario['password_hash'] ?? '');
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            throw new UnauthorizedException('Credenciales inválidas', 'CREDENCIALES_INVALIDAS');
        }

        $usuarioId = (int) ($usuario['usuario_id'] ?? $usuario['id']);
        $comercioId = isset($usuario['comercio_id']) ? (int) $usuario['comercio_id'] : null;

        // 4. Obtener roles asignados
        $roles = $this->sp->call('sp_usuario_roles_obtener_por_usuario', [$usuarioId]);
        $rolNombre = 'empleado'; // Rol predeterminado
        if (!empty($roles)) {
            $rolNombre = (string) ($roles[0]['rol_nombre'] ?? $roles[0]['nombre'] ?? 'empleado');
        }

        // 5. Emitir JWT
        $secret = (string) Env::get('JWT_SECRET', 'secret_key_waykipromo_2026_at_least_32_chars');
        $payload = [
            'sub'         => 'usr_' . $usuarioId,
            'usuario_id'  => $usuarioId,
            'email'       => $email,
            'nombre'      => (string) ($usuario['nombre'] ?? $email),
            'rol'         => $rolNombre,
            'comercio_id' => $comercioId,
            'iat'         => time(),
            'exp'         => time() + (86400 * 7), // 7 días de sesión
        ];

        $token = $this->jwt->encode($payload, $secret);

        return Response::ok([
            'token'   => $token,
            'usuario' => [
                'id'          => $usuarioId,
                'email'       => $email,
                'nombre'      => (string) ($usuario['nombre'] ?? $email),
                'rol'         => $rolNombre,
                'comercio_id' => $comercioId,
            ],
        ]);
    }

    /**
     * GET /api/v1/auth/perfil
     * Retorna el perfil del usuario autenticado a partir del token JWT.
     */
    public function profile(Request $request): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            throw new UnauthorizedException('Usuario no autenticado');
        }

        return Response::ok($user);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): Response
    {
        return Response::ok(['mensaje' => 'Sesión cerrada correctamente']);
    }
}
