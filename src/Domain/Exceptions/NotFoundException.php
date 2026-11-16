<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class NotFoundException extends HttpException
{
    public function __construct(
        string $message = 'Recurso no encontrado',
        string $errorCode = 'NO_ENCONTRADO',
        ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(404, $message, $errorCode, $details, null, $previous);
    }
}
