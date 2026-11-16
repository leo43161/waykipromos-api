<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\RequestHandlerInterface;
use App\Domain\Exceptions\HttpException;
use Throwable;

class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            return Response::json($e->getPayload(), $e->getStatusCode());
        } catch (Throwable $e) {
            error_log(sprintf(
                '[Unhandled Exception] %s: %s in %s:%d' . PHP_EOL . '%s',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ));

            return Response::error(
                'ERROR_INTERNO_SERVIDOR',
                'Ocurrió un error inesperado al procesar la solicitud.',
                null,
                500
            );
        }
    }
}
