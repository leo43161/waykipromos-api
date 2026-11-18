<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Config\Env;

final readonly class Request
{
    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $method = 'GET',
        public string $uri = '/',
        public array $queryParams = [],
        public array $parsedBody = [],
        public array $headers = [],
        public array $attributes = [],
        public string $basePath = ''
    ) {}

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        $path = (string) parse_url($this->uri, PHP_URL_PATH);
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }
        return '/' . ltrim($path, '/');
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getBaseUrl(): string
    {
        $scheme = $this->getHeader('x-forwarded-proto')
            ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http');

        $host = $this->getHeader('host') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $port = (string) ($_SERVER['SERVER_PORT'] ?? '');
        $isStandardPort = ($scheme === 'http' && $port === '80')
            || ($scheme === 'https' && $port === '443')
            || str_contains($host, ':');

        $hostWithPort = ($port !== '' && !$isStandardPort) ? "{$host}:{$port}" : $host;

        $baseUrl = "{$scheme}://{$hostWithPort}";

        if ($this->basePath !== '') {
            $baseUrl .= '/' . trim($this->basePath, '/');
        }

        return $baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    public function getBodyParam(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
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

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            $this->method,
            $this->uri,
            $this->queryParams,
            $this->parsedBody,
            $this->headers,
            $attributes,
            $this->basePath
        );
    }

    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $queryParams = [],
        array $parsedBody = [],
        array $headers = [],
        string $basePath = ''
    ): self {
        return new self(
            strtoupper($method),
            $uri,
            $queryParams,
            $parsedBody,
            $headers,
            [],
            $basePath
        );
    }

    public static function createFromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $basePath = (string) Env::get('BASE_PATH', '');

        // Detección automática del subdirectorio en servidores compartidos (ej: /waykipromo/api)
        if ($basePath === '' && isset($_SERVER['SCRIPT_NAME'])) {
            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            if ($scriptDir !== '' && $scriptDir !== '/') {
                $basePath = $scriptDir;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = (string) $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[strtolower($name)] = (string) $value;
            }
        }

        $parsedBody = [];
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $contentType = $headers['content-type'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $rawBody = (string) file_get_contents('php://input');
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $parsedBody = $decoded;
                }
            } else {
                $parsedBody = $_POST;
            }
        }

        return new self(
            $method,
            $uri,
            $_GET,
            $parsedBody,
            $headers,
            [],
            $basePath
        );
    }
}
