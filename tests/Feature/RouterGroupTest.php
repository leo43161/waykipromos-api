<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\Pipeline;
use App\Core\Middleware\RequestHandlerInterface;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Routes\ApiRoutes;
use PHPUnit\Framework\TestCase;

class RouterGroupTest extends TestCase
{
    private Router $router;
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $container = new Container();
        $this->router = new Router($container);
        ApiRoutes::register($this->router);

        $this->pipeline = new Pipeline([
            new CorsMiddleware(),
            new ErrorHandlerMiddleware(),
        ], $this->router);
    }

    public function testGroupPrefixesRegisterExpectedRoutes(): void
    {
        foreach (['/health', '/api/health', '/v1/health', '/api/v1/health'] as $path) {
            $request = Request::create('GET', $path);
            $response = $this->pipeline->handle($request);

            $this->assertContains($response->getStatusCode(), [200, 503], "Failed for path: {$path}");
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertArrayHasKey('ok', $body);
        }
    }

    public function testGroupMiddlewareInheritance(): void
    {
        $customRouter = new Router(new Container());

        $dummyMiddleware = new class implements MiddlewareInterface {
            public function process(Request $request, RequestHandlerInterface $handler): Response
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Group-Applied', 'true');
            }
        };

        $customRouter->group('/modulo', function (Router $r): void {
            $r->get('/recurso', function (): Response {
                return Response::ok(['status' => 'inside group']);
            });
        }, [$dummyMiddleware]);

        $pipeline = new Pipeline([new ErrorHandlerMiddleware()], $customRouter);

        $request = Request::create('GET', '/modulo/recurso');
        $response = $pipeline->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->getHeader('X-Group-Applied'));
    }

    public function testModularRoutesRespondOrRequireAuth(): void
    {
        // 1. Ruta pública de salud responde en ambos prefijos
        $req1 = Request::create('GET', '/api/v1/health');
        $res1 = $this->pipeline->handle($req1);
        $this->assertContains($res1->getStatusCode(), [200, 503]);

        $req2 = Request::create('GET', '/v1/health');
        $res2 = $this->pipeline->handle($req2);
        $this->assertContains($res2->getStatusCode(), [200, 503]);

        // 2. Ruta protegida con JWT rechaza con 401 sin cabecera Authorization
        $reqAuth = Request::create('GET', '/api/v1/mis-cupones');
        $resAuth = $this->pipeline->handle($reqAuth);
        $this->assertSame(401, $resAuth->getStatusCode());

        $reqAuth2 = Request::create('GET', '/v1/turista/cupones');
        $resAuth2 = $this->pipeline->handle($reqAuth2);
        $this->assertSame(401, $resAuth2->getStatusCode());

        $reqComercio = Request::create('GET', '/api/v1/comercio/promociones');
        $resComercio = $this->pipeline->handle($reqComercio);
        $this->assertSame(401, $resComercio->getStatusCode());

        $reqAdmin = Request::create('GET', '/api/v1/admin/promociones/pendientes');
        $resAdmin = $this->pipeline->handle($reqAdmin);
        $this->assertSame(401, $resAdmin->getStatusCode());
    }
}
