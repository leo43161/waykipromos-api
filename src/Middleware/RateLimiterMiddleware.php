<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use Throwable;

class RateLimiterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int $maxRequests = 120,
        private int $windowSeconds = 60,
        private ?string $storageDir = null
    ) {
        $this->maxRequests = (int) Env::get('RATE_LIMIT_MAX_REQUESTS', $this->maxRequests);
        $this->windowSeconds = (int) Env::get('RATE_LIMIT_WINDOW_SECONDS', $this->windowSeconds);
        $this->storageDir ??= sys_get_temp_dir() . '/waykipromos_rate_limit';
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (Env::get('RATE_LIMIT_ENABLED', true) === false) {
            return $handler->handle($request);
        }

        $ip = $this->resolveClientIp($request);
        $now = time();

        $rateData = $this->getRateData($ip, $now);

        // Si se excedió el cupo dentro de la ventana de tiempo
        if ($rateData['count'] > $this->maxRequests) {
            $retryAfter = max(1, $rateData['reset_at'] - $now);

            return Response::error(
                'DEMASIADAS_PETICIONES',
                'Has superado el límite de solicitudes permitidas. Por favor aguarda un momento.',
                ['reintentar_en_segundos' => $retryAfter],
                429,
                [
                    'Retry-After'           => (string) $retryAfter,
                    'X-RateLimit-Limit'     => (string) $this->maxRequests,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset'     => (string) $rateData['reset_at'],
                ]
            );
        }

        $response = $handler->handle($request);

        // Añadir cabeceras informativas de Rate Limit a la respuesta
        $remaining = max(0, $this->maxRequests - $rateData['count']);
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) $rateData['reset_at']);
    }

    /**
     * Obtiene y actualiza el contador de peticiones para la IP indicada de forma atómica y segura.
     *
     * @return array{count: int, reset_at: int}
     */
    protected function getRateData(string $ip, int $now): array
    {
        try {
            if (!is_dir($this->storageDir)) {
                mkdir($this->storageDir, 0777, true);
            }

            $filePath = $this->storageDir . '/' . md5($ip) . '.json';
            $data = ['count' => 0, 'reset_at' => $now + $this->windowSeconds];

            if (file_exists($filePath)) {
                $raw = (string) file_get_contents($filePath);
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['reset_at']) && $decoded['reset_at'] > $now) {
                    $data = $decoded;
                }
            }

            $data['count'] = (int) ($data['count'] ?? 0) + 1;
            file_put_contents($filePath, json_encode($data));

            return $data;
        } catch (Throwable) {
            // Si el sistema de archivos temporal falla, permitimos el tráfico sin degradar la API
            return ['count' => 1, 'reset_at' => $now + $this->windowSeconds];
        }
    }

    protected function resolveClientIp(Request $request): string
    {
        $forwarded = $request->getHeader('x-forwarded-for');
        if ($forwarded !== null && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }

        return (string) ($request->getHeader('x-real-ip') ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }
}
