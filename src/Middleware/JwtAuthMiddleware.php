<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\UnauthorizedException;
use App\Services\JwtService;
use Throwable;

class JwtAuthMiddleware implements MiddlewareInterface
{
    private static ?array $authenticatedUser = null;

    public function __construct(
        private JwtService $jwtService = new JwtService(),
        private ?string $secret = null
    ) {
        $this->secret ??= (string) Env::get('JWT_SECRET', '');
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $authHeader = $request->getHeader('authorization') ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            throw new UnauthorizedException('Token de acceso ausente o formato incorrecto (se requiere Bearer)', 'TOKEN_REQUERIDO');
        }

        if (strlen((string) $this->secret) < 32) {
            throw new UnauthorizedException('Servicio JWT no configurado en el servidor', 'JWT_CONFIG_ERROR');
        }

        $token = substr($authHeader, 7);

        try {
            $user = $this->jwtService->decode($token, $this->secret);
        } catch (Throwable $e) {
            throw new ForbiddenException('Token inválido, manipulado o expirado', 'TOKEN_INVALIDO');
        }

        self::$authenticatedUser = $user;
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }

    public static function getUser(): ?array
    {
        return self::$authenticatedUser;
    }

    public static function setUser(?array $user): void
    {
        self::$authenticatedUser = $user;
    }
}
