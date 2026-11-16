<?php

declare(strict_types=1);

namespace App\Core\Http;

final readonly class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode = 200,
        public string $body = '',
        public array $headers = []
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name, ?string $default = null): ?string
    {
        $normalized = strtolower($name);
        foreach ($this->headers as $key => $val) {
            if (strtolower($key) === $normalized) {
                return $val;
            }
        }
        return $default;
    }

    public function withStatus(int $code): self
    {
        return new self($code, $this->body, $this->headers);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->statusCode, $this->body, $headers);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(array $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $encoded = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new self($statusCode, $encoded, $headers);
    }

    /**
     * Standard success response matching Wayki Promo BFF specification
     *
     * @param mixed $data
     * @param array<string, mixed>|null $meta
     * @param int $statusCode
     * @param array<string, string> $headers
     * @return self
     */
    public static function ok(mixed $data = null, ?array $meta = null, int $statusCode = 200, array $headers = []): self
    {
        $payload = ['ok' => true];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $statusCode, $headers);
    }

    /**
     * Standard error response matching Wayki Promo BFF specification
     *
     * @param string $codigo
     * @param string $mensaje
     * @param array<string, mixed>|null $detalles
     * @param int $statusCode
     * @param array<string, string> $headers
     * @return self
     */
    public static function error(string $codigo, string $mensaje, ?array $detalles = null, int $statusCode = 400, array $headers = []): self
    {
        $payload = [
            'ok' => false,
            'error' => [
                'codigo' => $codigo,
                'mensaje' => $mensaje,
                'detalles' => $detalles,
            ],
        ];

        return self::json($payload, $statusCode, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function empty(int $statusCode = 204, array $headers = []): self
    {
        return new self($statusCode, '', $headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}", true);
            }
        }
        echo $this->body;
    }
}
