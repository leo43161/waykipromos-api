<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\RequestHandlerInterface;
use App\Middleware\ErrorHandlerMiddleware;
use PDOException;
use PHPUnit\Framework\TestCase;

class ErrorHandlerMiddlewareTest extends TestCase
{
    public function testHandlesMysqlSaturationWith503(): void
    {
        $middleware = new ErrorHandlerMiddleware();

        $handler = new class implements RequestHandlerInterface {
            public function handle(Request $request): Response {
                throw new PDOException("SQLSTATE[HY000] [1203] User huakiuser already has more than 'max_user_connections' active connections");
            }
        };

        $request = Request::create('GET', '/api/v1/ofertas');
        $response = $middleware->process($request, $handler);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('3', $response->getHeader('Retry-After'));

        $body = json_decode($response->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('SERVICIO_ALTA_DEMANDA', $body['error']['codigo']);
    }
}
