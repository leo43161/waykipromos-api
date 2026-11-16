<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class UnauthorizedException extends HttpException
{
    public function __construct(
        string $message = 'No autorizado',
        string $errorCode = 'NO_AUTORIZADO',
        ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(401, $message, $errorCode, $details, null, $previous);
    }
}
