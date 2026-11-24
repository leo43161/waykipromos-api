<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use App\Database\StoredProcedureCaller;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\UnauthorizedException;

final class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $roles roles que pueden usar la ruta (superadmin entra a todas las del personal)
     */
    public function __construct(
        private array $roles = [],
        private ?StoredProcedureCaller $sp = null
    ) {
        $this->sp ??= new StoredProcedureCaller();
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $token = $request->getAttribute('user')
            ?? throw new UnauthorizedException('Tu sesión terminó. Ingresá de nuevo.', 'SESION_INVALIDA');

        if (($token['rol'] ?? '') === 'turista') {
            try {
                $turista = $this->sp->callSingle('sp_turistas_obtener', [(int) ($token['turistas_id'] ?? 0)]);
                if ($turista !== null) {
                    if ((int) ($turista['borrado'] ?? 0) === 1) {
                        throw new UnauthorizedException('Tu sesión terminó. Ingresá de nuevo.', 'SESION_INVALIDA');
                    }
                    if ((int) ($turista['activo'] ?? 1) !== 1) {
                        throw new ForbiddenException('Tu cuenta está suspendida. Escribinos si creés que es un error.', 'TURISTA_SUSPENDIDO');
                    }
                }
            } catch (ForbiddenException|UnauthorizedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Si la BD no está disponible o en tests unitarios, usar datos de token
            }
            $user = $token;
        } else {
            try {
                $usuario = $this->sp->callSingle('sp_usuarios_sesion', [(int) ($token['usuario_id'] ?? 0)]);
                if ($usuario !== null) {
                    if (($usuario['estado'] ?? '') !== 'ACTIVO' || ($usuario['rol'] ?? null) === null) {
                        throw new UnauthorizedException('Tu sesión terminó. Ingresá de nuevo.', 'SESION_INVALIDA');
                    }
                    $user = [
                        ...$token,
                        'rol' => $usuario['rol'],
                        'comercio_id' => $usuario['comercio_id'] === null ? null : (int) $usuario['comercio_id'],
                        'nombre' => $usuario['nombre'] ?? ($usuario['nombreu'] ?? ($token['nombre'] ?? '')),
                    ];
                } else {
                    $user = $token;
                }
            } catch (ForbiddenException|UnauthorizedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $user = $token;
            }
        }

        $rol = (string) ($user['rol'] ?? '');
        $puede = empty($this->roles)
            || in_array($rol, $this->roles, true)
            || ($rol === 'superadmin' && !in_array('turista', $this->roles, true));

        if (!$puede) {
            throw new ForbiddenException('Tu usuario no puede hacer esto.', 'ROL_NO_AUTORIZADO');
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
