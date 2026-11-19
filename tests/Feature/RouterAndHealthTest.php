<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\HealthController;
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use PHPUnit\Framework\TestCase;

class RouterAndHealthTest extends TestCase
{
    private Pipeline $pipeline;
    private Router $router;

    protected function setUp(): void
    {
        $container = new Container();
        $this->router = new Router($container);
        $this->router->get('/health', [HealthController::class, 'check']);

        $this->pipeline = new Pipeline([
            new CorsMiddleware(),
            new ErrorHandlerMiddleware(),
        ], $this->router);
    }

    public function testHealthEndpointReturnsStandardEnvelope(): void
    {
        $request = Request::create('GET', '/health');
        $response = $this->pipeline->handle($request);

        $this->assertContains($response->getStatusCode(), [200, 503]);
        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertArrayHasKey('ok', $body);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('API REST WaykiPromos', $body['data']['service']);
        $this->assertSame('API REST WaykiPromos', $body['data']['servicio']);
    }

    public function testNonExistentRouteThrowsNotFoundException(): void
    {
        $request = Request::create('GET', '/ruta-que-no-existe');
        $response = $this->pipeline->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertFalse($body['ok']);
        $this->assertSame('NO_ENCONTRADO', $body['error']['codigo']);
    }
}
