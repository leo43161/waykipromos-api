<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class InternalServerErrorException extends HttpException
{
    public function __construct(
        string $message = 'Error interno procesando la consulta',
        ?Throwable $previous = null
    ) {
        parent::__construct(500, $message, null, null, $previous);
    }
}
