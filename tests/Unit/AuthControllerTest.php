<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Core\Http\Request;
use App\Database\StoredProcedureCaller;
use App\Services\GoogleAuthService;
use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

class AuthControllerTest extends TestCase
{
    public function testGoogleLoginCreatesNewTuristaWithNineParameters(): void
    {
        $mockSp = $this->createMock(StoredProcedureCaller::class);
        $mockGoogleAuth = $this->createMock(GoogleAuthService::class);
        $jwt = new JwtService();

        $mockGoogleAuth->method('verifyIdToken')->willReturn([
            'sub'         => 'google_sub_12345',
            'email'       => 'turista@prueba.com',
            'name'        => 'Juan Perez',
            'given_name'  => 'Juan',
            'family_name' => 'Perez',
            'picture'     => 'https://lh3.googleusercontent.com/foto.jpg',
        ]);

        // 1. Simular que no existe en BD
        $mockSp->expects($this->once())
            ->method('callSingle')
            ->with('sp_turistas_obtener_por_google_sub', ['google_sub_12345'])
            ->willReturn(null);

        // 2. Verificar que sp_turistas_crear recibe exactamente los 9 parámetros de entrada
        $mockSp->expects($this->once())
            ->method('callWithOut')
            ->with(
                'sp_turistas_crear',
                $this->callback(function (array $params) {
                    $this->assertCount(9, $params, 'sp_turistas_crear debe recibir exactamente 9 parámetros');
                    $this->assertSame('google_sub_12345', $params[0]); // p_google_sub
                    $this->assertSame('turista@prueba.com', $params[1]); // p_email
                    $this->assertSame(1, $params[2]); // p_email_verified
                    $this->assertSame('Juan Perez', $params[3]); // p_nombre_completo
                    $this->assertSame('https://lh3.googleusercontent.com/foto.jpg', $params[4]); // p_foto_url
                    $this->assertSame('Juan', $params[5]); // p_nombre
                    $this->assertSame('Perez', $params[6]); // p_apellido
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[7]); // p_creado_en
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[8]); // p_ultimo_acceso
                    return true;
                })
            )
            ->willReturn('101');

        $controller = new AuthController($mockSp, $jwt, $mockGoogleAuth);
        $request = new Request('POST', '/api/v1/auth/google', [], ['id_token' => 'valid_token']);

        $response = $controller->googleLogin($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(101, $body['data']['turista']['id']);
        $this->assertNotEmpty($body['data']['token']);
    }

    public function testGoogleLoginExistingTuristaUpdatesLastAccess(): void
    {
        $mockSp = $this->createMock(StoredProcedureCaller::class);
        $mockGoogleAuth = $this->createMock(GoogleAuthService::class);
        $jwt = new JwtService();

        $mockGoogleAuth->method('verifyIdToken')->willReturn([
            'sub'         => 'google_sub_existing',
            'email'       => 'turista.existente@prueba.com',
            'name'        => 'Maria Gomez',
            'given_name'  => 'Maria',
            'family_name' => 'Gomez',
            'picture'     => 'https://lh3.googleusercontent.com/foto2.jpg',
        ]);

        // Simular que el turista ya existe
        $mockSp->expects($this->once())
            ->method('callSingle')
            ->with('sp_turistas_obtener_por_google_sub', ['google_sub_existing'])
            ->willReturn(['turistas_id' => 55]);

        // sp_turistas_crear NO debe llamarse
        $mockSp->expects($this->never())->method('callWithOut');

        // sp_turistas_actualizar DEBE llamarse para registrar p_ultimo_acceso
        $mockSp->expects($this->once())
            ->method('call')
            ->with(
                'sp_turistas_actualizar',
                $this->callback(function (array $params) {
                    $this->assertCount(11, $params, 'sp_turistas_actualizar debe recibir 11 parámetros');
                    $this->assertSame(55, $params[0]); // p_turistas_id
                    $this->assertSame('google_sub_existing', $params[1]); // p_google_sub
                    $this->assertSame('turista.existente@prueba.com', $params[2]); // p_email
                    $this->assertSame(1, $params[3]); // p_email_verified
                    $this->assertSame('Maria Gomez', $params[4]); // p_nombre_completo
                    $this->assertSame('https://lh3.googleusercontent.com/foto2.jpg', $params[5]); // p_foto_url
                    $this->assertSame('Maria', $params[6]); // p_nombre
                    $this->assertSame('Gomez', $params[7]); // p_apellido
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params[8]); // p_ultimo_acceso
                    $this->assertSame(1, $params[9]); // p_activo
                    $this->assertSame(0, $params[10]); // p_borrado
                    return true;
                })
            )
            ->willReturn([]);

        $controller = new AuthController($mockSp, $jwt, $mockGoogleAuth);
        $request = new Request('POST', '/api/v1/auth/google', [], ['id_token' => 'valid_token']);

        $response = $controller->googleLogin($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['ok']);
        $this->assertSame(55, $body['data']['turista']['id']);
    }
}
