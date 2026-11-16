<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use Throwable;

class FileLoggerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ?string $logPath = null
    ) {
        $this->logPath ??= dirname(__DIR__, 2) . '/storage/logs/app.log';
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $start = microtime(true);

        try {
            $response = $handler->handle($request);
            $statusCode = $response->getStatusCode();
        } catch (Throwable $e) {
            $this->writeLog($request, 500, microtime(true) - $start);
            throw $e;
        }

        $this->writeLog($request, $statusCode, microtime(true) - $start);
        return $response;
    }

    protected function writeLog(Request $request, int $statusCode, float $durationSec): void
    {
        $ms = (int) round($durationSec * 1000);
        $method = $request->getMethod();
        $uri = $request->getUri();
        $ua = $request->getHeader('user-agent') ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $request->getHeader('host') ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fullUrl = "{$scheme}://{$host}{$uri}";

        $logLine = "[{$timestamp}] {$method} {$fullUrl} - Status: {$statusCode} - Duration: {$ms}ms - UA: {$ua}\n";

        try {
            $dir = dirname($this->logPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->logPath, $logLine, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Ignore logging errors to keep response flowing
        }
    }
}
