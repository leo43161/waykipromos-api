<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JwtService;
use PHPUnit\Framework\TestCase;

class JwtServiceTest extends TestCase
{
    private JwtService $jwt;
    private string $secret = 'mi_super_clave_secreta_para_pruebas_unitarias_2026';

    protected function setUp(): void
    {
        $this->jwt = new JwtService();
    }

    public function testEncodeAndDecodeValidToken(): void
    {
        $payload = [
            'sub'         => 'usr_123',
            'email'       => 'test@tucumanturismo.gob.ar',
            'rol'         => 'turista',
            'exp'         => time() + 3600,
        ];

        $token = $this->jwt->encode($payload, $this->secret);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $decoded = $this->jwt->decode($token, $this->secret);
        $this->assertSame('usr_123', $decoded['sub']);
        $this->assertSame('test@tucumanturismo.gob.ar', $decoded['email']);
        $this->assertSame('turista', $decoded['rol']);
    }

    public function testExpiredTokenThrowsException(): void
    {
        $payload = [
            'sub' => 'usr_expired',
            'exp' => time() - 100, // En el pasado
        ];

        $token = $this->jwt->encode($payload, $this->secret);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Token expirado');

        $this->jwt->decode($token, $this->secret);
    }

    public function testTamperedTokenThrowsException(): void
    {
        $payload = ['sub' => 'usr_tampered', 'exp' => time() + 3600];
        $token = $this->jwt->encode($payload, $this->secret);

        // Modificamos un carácter
        $tamperedToken = substr($token, 0, -2) . 'ab';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Firma de token inválida');

        $this->jwt->decode($tamperedToken, $this->secret);
    }
}
