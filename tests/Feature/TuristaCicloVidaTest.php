<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\CuponesController;
use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Middleware\Pipeline;
use App\Core\Router\Router;
use App\Database\StoredProcedureCaller;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\JwtAuthMiddleware;
use App\Routes\ApiRoutes;
use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

class TuristaCicloVidaTest extends TestCase
{
    private Pipeline $pipeline;
    private Container $container;
    private JwtService $jwt;
    private string $secret = 'test_secret_key_waykipromos_2026_at_least_32_chars';

    protected function setUp(): void
    {
        putenv("JWT_SECRET={$this->secret}");
        $_ENV['JWT_SECRET'] = $this->secret;
        $_ENV['RATE_LIMIT_ENABLED'] = 'false';

        $this->container = new Container();
        $router = new Router($this->container);
        ApiRoutes::register($router);

        $this->pipeline = new Pipeline([
            new CorsMiddleware(),
            new ErrorHandlerMiddleware(),
        ], $router);

        $this->jwt = new JwtService();
    }

    protected function tearDown(): void
    {
        unset($_ENV['RATE_LIMIT_ENABLED']);
        putenv('RATE_LIMIT_ENABLED');
    }

    private function generarToken(int $id, string $rol = 'turista'): string
    {
        return $this->jwt->encode([
            'sub'         => 'tur_' . $id,
            'turistas_id' => $id,
            'email'       => "turista{$id}@test.com",
            'nombre'      => "Turista {$id}",
            'rol'         => $rol,
            'comercio_id' => $rol === 'empleado' ? 10 : null,
            'iat'         => time(),
            'exp'         => time() + 86400 * 30,
        ], $this->secret);
    }

    public function testAccesoSinAutenticacionResponde401TokenRequerido(): void
    {
        foreach (['/api/v1/circuitos', '/api/v1/promociones', '/api/v1/localidades'] as $uri) {
            $req = Request::create('GET', $uri);
            $res = $this->pipeline->handle($req);

            $this->assertSame(401, $res->getStatusCode(), "Fallo en URI: {$uri}");
            $body = json_decode($res->getBody(), true);
            $this->assertFalse($body['ok']);
            $this->assertSame('TOKEN_REQUERIDO', $body['error']['codigo']);
        }
    }

    public function testSoloTuristasPuedenGuardarCupones(): void
    {
        $tokenEmpleado = $this->generarToken(5, 'empleado');
        $req = Request::create('POST', '/api/v1/cupones', [], ['promocion_id' => 201], [
            'authorization' => "Bearer {$tokenEmpleado}"
        ]);

        $res = $this->pipeline->handle($req);
        $this->assertSame(403, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertFalse($body['ok']);
        $this->assertSame('SOLO_TURISTAS', $body['error']['codigo']);
    }

    public function testContratoEmisionCuponCumpleDirectivaEatt(): void
    {
        $mockSp = $this->createMock(StoredProcedureCaller::class);

        // Sin cupones previos
        $mockSp->method('call')
            ->willReturnCallback(function ($sp, $params) {
                if ($sp === 'sp_cupones_listar_por_turista') {
                    return [];
                }
                return [];
            });

        $mockSp->method('callSingle')
            ->willReturnCallback(function ($sp, $params) {
                if ($sp === 'sp_promociones_obtener') {
                    return [
                        'promocion_id'     => 201,
                        'titulo'           => '30% en Hotelería de Altura',
                        'comercio_nombre'  => 'Hotel Sol Tafí',
                        'circuito_nombre'  => 'Valles Calchaquíes',
                        'estado'           => 'PUBLICADA',
                        'activo'           => 1,
                        'stock_disponible' => 15,
                        'fecha_fin'        => '2026-12-31',
                    ];
                }
                if ($sp === 'sp_cupones_verificar_turista_promo') {
                    return null;
                }
                return null;
            });

        $mockSp->method('callWithOut')
            ->willReturn(1842);

        $cuponesController = new CuponesController($mockSp);

        $tokenTurista = $this->generarToken(2, 'turista');
        $req = Request::create('POST', '/api/v1/cupones', [], ['promocion_id' => 201])
            ->withAttribute('user', [
                'turistas_id' => 2,
                'rol'         => 'turista',
            ]);

        $res = $cuponesController->emitir($req);

        $this->assertSame(201, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);

        $this->assertTrue($body['ok']);
        $data = $body['data'];

        $this->assertSame(1842, $data['cupon_id']);
        $this->assertStringStartsWith('WYK-', $data['codigo_cupon']);
        $this->assertSame(201, $data['promocion_id']);
        $this->assertSame('30% en Hotelería de Altura', $data['titulo_oferta']);
        $this->assertSame('Hotel Sol Tafí', $data['comercio']);
        $this->assertSame('Valles Calchaquíes', $data['circuito']);
        $this->assertSame(2, $data['turistas_id']);
        $this->assertSame('EMITIDO', $data['estado']);
        $this->assertNotNull($data['emitido_en']);
        $this->assertNotNull($data['vence_en']);
        $this->assertSame('¡Cupón guardado con éxito en tu perfil! Preséntalo en el mostrador del prestador para obtener el beneficio.', $data['mensaje']);
    }

    public function testRechazaEmisionAlSuperarLimite5CuponesActivos(): void
    {
        $mockSp = $this->createMock(StoredProcedureCaller::class);

        // 5 cupones activos existentes
        $mockSp->method('call')
            ->willReturnCallback(function ($sp, $params) {
                if ($sp === 'sp_cupones_listar_por_turista') {
                    return [
                        ['cupon_id' => 1, 'estado' => 'EMITIDO', 'vence_en' => date('Y-m-d H:i:s', strtotime('+5 days'))],
                        ['cupon_id' => 2, 'estado' => 'EMITIDO', 'vence_en' => date('Y-m-d H:i:s', strtotime('+6 days'))],
                        ['cupon_id' => 3, 'estado' => 'EMITIDO', 'vence_en' => date('Y-m-d H:i:s', strtotime('+7 days'))],
                        ['cupon_id' => 4, 'estado' => 'EMITIDO', 'vence_en' => date('Y-m-d H:i:s', strtotime('+8 days'))],
                        ['cupon_id' => 5, 'estado' => 'EMITIDO', 'vence_en' => date('Y-m-d H:i:s', strtotime('+9 days'))],
                    ];
                }
                return [];
            });

        $cuponesController = new CuponesController($mockSp);

        $req = Request::create('POST', '/api/v1/cupones', [], ['promocion_id' => 201])
            ->withAttribute('user', [
                'turistas_id' => 2,
                'rol'         => 'turista',
            ]);

        $this->expectException(\App\Domain\Exceptions\ConflictException::class);
        $cuponesController->emitir($req);
    }

    public function testTuristaNoPuedeValidarNiConfirmarCanjes(): void
    {
        $tokenTurista = $this->generarToken(2, 'turista');

        // Intento de validar
        $reqVal = Request::create('POST', '/api/v1/canjes/validar', [], ['codigo' => 'WYK-CAL-1234'], [
            'authorization' => "Bearer {$tokenTurista}"
        ]);
        $resVal = $this->pipeline->handle($reqVal);
        $this->assertSame(403, $resVal->getStatusCode());
        $bodyVal = json_decode($resVal->getBody(), true);
        $this->assertSame('ROL_NO_AUTORIZADO', $bodyVal['error']['codigo']);

        // Intento de confirmar
        $reqConf = Request::create('POST', '/api/v1/canjes/confirmar', [], ['codigo' => 'WYK-CAL-1234'], [
            'authorization' => "Bearer {$tokenTurista}"
        ]);
        $resConf = $this->pipeline->handle($reqConf);
        $this->assertSame(403, $resConf->getStatusCode());
        $bodyConf = json_decode($resConf->getBody(), true);
        $this->assertSame('ROL_NO_AUTORIZADO', $bodyConf['error']['codigo']);
    }

    public function testAliasPromocionesIdCanjearEmiteCupon(): void
    {
        $mockSp = $this->createMock(StoredProcedureCaller::class);
        $mockSp->method('call')->willReturn([]);
        $mockSp->method('callSingle')->willReturn(null); // promo no encontrada
        $this->container->set(CuponesController::class, new CuponesController($mockSp));

        $tokenTurista = $this->generarToken(2, 'turista');

        $req = Request::create('POST', '/api/v1/promociones/999/canjear', [], [], [
            'authorization' => "Bearer {$tokenTurista}"
        ]);

        $res = $this->pipeline->handle($req);

        // Como el ID 999 no existe, responde 404 PROMOCION_NO_ENCONTRADA
        // validando que la ruta está perfectamente mapeada e interceptada por JwtAuthMiddleware
        $this->assertSame(404, $res->getStatusCode());
        $body = json_decode($res->getBody(), true);
        $this->assertSame('PROMOCION_NO_ENCONTRADA', $body['error']['codigo']);
    }
}
