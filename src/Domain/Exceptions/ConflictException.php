<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class ConflictException extends HttpException
{
    public function __construct(
        string $message = 'Conflicto de negocio',
        string $errorCode = 'CONFLICTO_NEGOCIO',
        ?array $details = null,
        ?Throwable $previous = null
    ) {
        parent::__construct(409, $message, $errorCode, $details, null, $previous);
    }
}
