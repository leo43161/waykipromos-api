<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class ForbiddenException extends HttpException
{
    public function __construct(
        string $message = 'Acceso denegado',
        string $errorCode = 'ACCESO_DENEGADO',
        ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(403, $message, $errorCode, $details, null, $previous);
    }
}
