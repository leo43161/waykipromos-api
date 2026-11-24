<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Código del cupón: "W" + 6 caracteres sin 0/O ni 1/I (fáciles de dictar en el mostrador).
 */
final class Codigo
{
    private const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function nuevo(): string
    {
        $codigo = 'W';
        for ($i = 0; $i < 6; $i++) {
            $codigo .= self::ALFABETO[random_int(0, 31)];
        }
        return $codigo;
    }

    /**
     * Lo que tipea o escanea el empleado: mayúsculas, sin espacios ni guiones.
     */
    public static function normalizar(mixed $texto): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $texto));
    }
}
