<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $allowedOrigins = array_filter(array_map('trim', explode(',', (string) Env::get(
            'CORS_ALLOWED_ORIGINS',
            Env::get('ALLOWED_ORIGINS', 'https://waykipromo.tucumanturismo.gob.ar,http://localhost:3000,http://localhost:4321,http://localhost:5173')
        ))));

        $origin = $request->getHeader('origin');
        $isAllowed = $origin !== null && (in_array($origin, $allowedOrigins, true) || in_array('*', $allowedOrigins, true));

        $headers = [
            'Vary' => 'Origin',
        ];

        if ($isAllowed) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
            $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization, X-API-Key, X-Requested-With';
            $headers['Access-Control-Max-Age'] = '600';
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($request->getMethod() === 'OPTIONS') {
            return Response::empty($isAllowed ? 204 : 403, $headers);
        }

        $response = $handler->handle($request);
        foreach ($headers as $key => $value) {
            $response = $response->withHeader($key, $value);
        }

        return $response;
    }
}
