<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Exception;
use Throwable;

abstract class HttpException extends Exception
{
    /**
     * @param int $statusCode
     * @param string $message
     * @param string $errorCode
     * @param array<string, mixed>|null $details
     * @param array<string, mixed>|null $customPayload
     * @param Throwable|null $previous
     */
    public function __construct(
        protected int $statusCode,
        string $message,
        protected string $errorCode = 'ERROR_GENERICO',
        protected ?array $details = null,
        protected ?array $customPayload = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /**
     * Returns the structured JSON payload for this exception.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        if ($this->customPayload !== null) {
            return $this->customPayload;
        }

        return [
            'ok' => false,
            'error' => [
                'codigo' => $this->errorCode,
                'mensaje' => $this->getMessage(),
                'detalles' => $this->details,
            ],
        ];
    }
}
