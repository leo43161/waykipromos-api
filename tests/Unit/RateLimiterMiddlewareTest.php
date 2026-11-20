<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\RequestHandlerInterface;
use App\Middleware\RateLimiterMiddleware;
use PHPUnit\Framework\TestCase;

class RateLimiterMiddlewareTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test_waykipromos_rl_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $limiter = new RateLimiterMiddleware(maxRequests: 5, windowSeconds: 60, storageDir: $this->tempDir);

        $handler = new class implements RequestHandlerInterface {
            public function handle(Request $request): Response {
                return Response::ok(['saludo' => 'hola']);
            }
        };

        $request = Request::create('GET', '/api/v1/ofertas', [], ['x-forwarded-for' => '192.168.1.50']);

        $response = $limiter->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('5', $response->getHeader('X-RateLimit-Limit'));
        $this->assertSame('4', $response->getHeader('X-RateLimit-Remaining'));
        $this->assertNotNull($response->getHeader('X-RateLimit-Reset'));
    }

    public function testBlocksRequestsExceedingLimitWith429(): void
    {
        $limiter = new RateLimiterMiddleware(maxRequests: 2, windowSeconds: 60, storageDir: $this->tempDir);

        $handler = new class implements RequestHandlerInterface {
            public function handle(Request $request): Response {
                return Response::ok(['status' => 'ok']);
            }
        };

        $request = Request::create('GET', '/api/v1/ofertas', [], ['x-forwarded-for' => '10.0.0.1']);

        // Petición 1 (Pasa)
        $r1 = $limiter->process($request, $handler);
        $this->assertSame(200, $r1->getStatusCode());

        // Petición 2 (Pasa)
        $r2 = $limiter->process($request, $handler);
        $this->assertSame(200, $r2->getStatusCode());

        // Petición 3 (Debe ser bloqueada con 429)
        $r3 = $limiter->process($request, $handler);
        $this->assertSame(429, $r3->getStatusCode());
        $this->assertNotNull($r3->getHeader('Retry-After'));
        $this->assertSame('0', $r3->getHeader('X-RateLimit-Remaining'));

        $body = json_decode($r3->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('DEMASIADAS_PETICIONES', $body['error']['codigo']);
    }
}
