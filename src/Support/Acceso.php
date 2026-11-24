<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Exceptions\ForbiddenException;
use App\Domain\Exceptions\NotFoundException;

/**
 * Sobre qué comercio actúa cada usuario. El Ente ve todo; el comercio, solo lo suyo.
 */
final class Acceso
{
    public static function esEnte(array $user): bool
    {
        return in_array($user['rol'] ?? '', ['admin_ente', 'superadmin'], true);
    }

    /**
     * Comercio para filtrar: el propio (encargado/empleado) o el pedido por el Ente (null = todos).
     */
    public static function comercio(array $user, mixed $pedido = null): ?int
    {
        if (self::esEnte($user)) {
            return (int) $pedido > 0 ? (int) $pedido : null;
        }
        $propio = (int) ($user['comercio_id'] ?? 0);
        if ($propio <= 0) {
            throw new ForbiddenException('Tu usuario no tiene un comercio asignado.', 'SIN_COMERCIO');
        }
        if ((int) $pedido > 0 && (int) $pedido !== $propio) {
            throw new ForbiddenException('No tenés acceso a ese comercio.', 'SIN_ACCESO_AL_COMERCIO');
        }
        return $propio;
    }

    /**
     * Un recurso de otro comercio responde 404, igual que uno que no existe (no se revela).
     */
    public static function delComercio(array $user, ?array $fila, string $mensaje = 'No existe o no es de tu comercio.'): array
    {
        if ($fila === null || (!self::esEnte($user) && (int) ($fila['comercio_id'] ?? 0) !== (int) ($user['comercio_id'] ?? 0))) {
            throw new NotFoundException($mensaje, 'NO_ENCONTRADO');
        }
        return $fila;
    }

    /**
     * Para el canje: el comercio propio; superadmin sin control (null).
     */
    public static function comercioCanje(array $user): ?int
    {
        return ($user['rol'] ?? '') === 'superadmin' ? null : self::comercio($user);
    }

    public static function exigirEnte(array $user): void
    {
        if (!self::esEnte($user)) {
            throw new ForbiddenException('Solo el Ente puede hacer esto.', 'ROL_NO_AUTORIZADO');
        }
    }
}
