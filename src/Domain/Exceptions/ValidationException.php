<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

class ValidationException extends HttpException
{
    /**
     * @param string $message
     * @param array<string, mixed>|null $details
     * @param string $errorCode
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message = 'Error de validación',
        ?array $details = null,
        string $errorCode = 'VALIDACION_FALLIDA',
        ?Throwable $previous = null
    ) {
        parent::__construct(400, $message, $errorCode, $details, null, $previous);
    }
}
