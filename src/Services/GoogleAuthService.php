<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Exceptions\UnauthorizedException;
use Throwable;

class GoogleAuthService
{
    /**
     * Verifies a Google ID token and returns its verified claims.
     *
     * @param string $idToken
     * @return array<string, mixed>
     * @throws UnauthorizedException
     */
    public function verifyIdToken(string $idToken): array
    {
        if (trim($idToken) === '') {
            throw new UnauthorizedException('Token de Google ausente', 'GOOGLE_TOKEN_REQUERIDO');
        }

        // Endpoint oficial de validación de tokens de Google OAuth
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                throw new UnauthorizedException('Error de comunicación con los servidores de autenticación de Google', 'GOOGLE_SERVICE_ERROR');
            }

            $claims = json_decode($response, true);
            if (!is_array($claims) || isset($claims['error_description']) || !isset($claims['sub'])) {
                $msg = $claims['error_description'] ?? 'Token de Google inválido o caducado';
                throw new UnauthorizedException($msg, 'GOOGLE_TOKEN_INVALIDO');
            }

            return $claims;
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnauthorizedException('Fallo al validar credencial de Google: ' . $e->getMessage(), 'GOOGLE_VALIDACION_FALLIDA', null, $e);
        }
    }
}
