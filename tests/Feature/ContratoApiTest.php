<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Config\Env;
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Routes\ApiRoutes;
use App\Services\JwtService;
use App\Support\Codigo;
use PHPUnit\Framework\TestCase;

final class ContratoApiTest extends TestCase
{
    private Pipeline $pipeline;
    private JwtService $jwt;
    private string $secret;

    protected function setUp(): void
    {
        $this->secret = 'super_secret_waykipromo_jwt_key_2026_at_least_32_chars';
        $_ENV['JWT_SECRET'] = $this->secret;
        putenv("JWT_SECRET={$this->secret}");

        $container = new Container();
        $router = new Router($container);
        ApiRoutes::register($router);

        $this->pipeline = new Pipeline([
            new ErrorHandlerMiddleware(),
            new CorsMiddleware(),
        ], $router);

        $this->jwt = new JwtService();
    }

    public function testRutasPublicasDelContratoRespondenSinToken(): void
    {
        $publicas = [
            '/v1/categorias',
            '/v1/modalidades',
            '/v1/circuitos',
            '/v1/localidades',
            '/v1/encuesta/opciones',
            '/api/v1/categorias',
        ];

        foreach ($publicas as $uri) {
            $req = Request::create('GET', $uri);
            $res = $this->pipeline->handle($req);

            $this->assertSame(200, $res->getStatusCode(), "Fallo en ruta pública: {$uri}");
            $body = json_decode($res->getBody(), true);
            $this->assertTrue($body['ok']);
            $this->assertIsArray($body['data']);
        }
    }

    public function testRutasTuristaRequierenTokenYRechazanSinEl(): void
    {
        $turistaRutas = [
            'GET'    => ['/v1/turista/perfil', '/v1/turista/cupones', '/v1/turista/encuesta'],
            'DELETE' => ['/v1/turista/cuenta'],
            'POST'   => ['/v1/turista/cupones', '/v1/turista/encuesta'],
        ];

        foreach ($turistaRutas as $metodo => $uris) {
            foreach ($uris as $uri) {
                $req = Request::create($metodo, $uri);
                $res = $this->pipeline->handle($req);

                $this->assertSame(401, $res->getStatusCode(), "Debe requerir token: {$metodo} {$uri}");
                $body = json_decode($res->getBody(), true);
                $this->assertFalse($body['ok']);
                $this->assertSame('TOKEN_REQUERIDO', $body['error']['codigo']);
            }
        }
    }

    public function testRutasPanelRechazanSinToken(): void
    {
        $panelRutas = [
            '/v1/panel/sesion',
            '/v1/panel/resumen',
            '/v1/panel/canjes',
            '/v1/panel/promociones',
            '/v1/panel/comercios',
            '/v1/panel/usuarios',
            '/v1/panel/visitantes',
            '/v1/panel/reportes',
            '/v1/panel/actividad',
        ];

        foreach ($panelRutas as $uri) {
            $req = Request::create('GET', $uri);
            $res = $this->pipeline->handle($req);

            $this->assertSame(401, $res->getStatusCode(), "Debe exigir autenticación: {$uri}");
            $body = json_decode($res->getBody(), true);
            $this->assertFalse($body['ok']);
            $this->assertSame('TOKEN_REQUERIDO', $body['error']['codigo']);
        }
    }

    public function testTuristaNoPuedeAccederRutasDelPanel(): void
    {
        $tokenTurista = $this->jwt->encode([
            'sub'         => 'tur_123',
            'turistas_id' => 123,
            'email'       => 'turista@wayki.gob.ar',
            'nombre'      => 'Ana',
            'rol'         => 'turista',
            'comercio_id' => null,
            'iat'         => time(),
            'exp'         => time() + 3600,
        ], $this->secret);

        $req = Request::create('GET', '/v1/panel/promociones', [], [], [
            'authorization' => "Bearer {$tokenTurista}",
        ]);

        $res = $this->pipeline->handle($req);
        $this->assertSame(403, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('ROL_NO_AUTORIZADO', $body['error']['codigo']);
    }

    public function testGeneracionYNormalizacionDeCodigoDeCupon(): void
    {
        $codigo = Codigo::nuevo();
        $this->assertSame(7, strlen($codigo));
        $this->assertStringStartsWith('W', $codigo);

        // Sin 0, O, 1, I
        $this->assertDoesNotMatchRegularExpression('/[0O1I]/', $codigo);

        // Normalización
        $this->assertSame('W9BDSXB', Codigo::normalizar('w9bd-sxb'));
        $this->assertSame('W9BDSXB', Codigo::normalizar('  W9bd  sxb '));
    }
}
