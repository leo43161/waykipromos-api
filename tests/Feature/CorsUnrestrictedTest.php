<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Routes\ApiRoutes;
use PHPUnit\Framework\TestCase;

class CorsUnrestrictedTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $container = new Container();
        $router = new Router($container);
        ApiRoutes::register($router);

        $this->pipeline = new Pipeline([
            new CorsMiddleware(),
        ], $router);
    }

    public function testPreflightOptionsReturns204ForAnyOrigin(): void
    {
        $request = Request::create(
            'OPTIONS',
            '/api/v1/health',
            [],
            [],
            ['origin' => 'http://localhost:9999']
        );

        $response = $this->pipeline->handle($request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('http://localhost:9999', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('*', $response->getHeader('Access-Control-Allow-Headers'));
    }

    public function testGetRequestReflectsArbitraryOrigin(): void
    {
        $request = Request::create(
            'GET',
            '/health',
            [],
            [],
            ['origin' => 'https://frontend-random.dev']
        );

        $response = $this->pipeline->handle($request);

        $this->assertSame('https://frontend-random.dev', $response->getHeader('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
    }
}
