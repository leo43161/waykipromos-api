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
        // Sin restricciones: refleja el origen entrante o permite wildcard
        $origin = $request->getHeader('origin') ?? '*';

        $headers = [
            'Access-Control-Allow-Origin'  => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD',
            'Access-Control-Allow-Headers' => '*',
            'Access-Control-Max-Age'       => '86400',
        ];

        if ($origin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Vary'] = 'Origin';
        }

        if ($request->getMethod() === 'OPTIONS') {
            return Response::empty(204, $headers);
        }

        $response = $handler->handle($request);
        foreach ($headers as $key => $value) {
            $response = $response->withHeader($key, $value);
        }

        return $response;
    }
}
