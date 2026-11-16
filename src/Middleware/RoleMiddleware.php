<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\UnauthorizedException;

class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedRoles
     */
    public function __construct(
        private array $allowedRoles = []
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');

        if ($user === null) {
            throw new UnauthorizedException('Usuario no autenticado', 'USUARIO_NO_AUTENTICADO');
        }

        $userRole = (string) ($user['rol'] ?? '');

        // superadmin always has access
        if ($userRole === 'superadmin') {
            return $handler->handle($request);
        }

        if (!empty($this->allowedRoles) && !in_array($userRole, $this->allowedRoles, true)) {
            throw new ForbiddenException(
                "Acceso restringido para el rol '{$userRole}'. Se requiere: " . implode(', ', $this->allowedRoles),
                'ROL_NO_AUTORIZADO'
            );
        }

        return $handler->handle($request);
    }
}
