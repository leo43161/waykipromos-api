<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;

class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private array $middlewares = [],
        private ?RequestHandlerInterface $fallbackHandler = null
    ) {}

    /**
     * Appends a middleware to the pipeline.
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Sets the terminal fallback handler (e.g. the router).
     */
    public function withFallback(RequestHandlerInterface $fallbackHandler): self
    {
        $this->fallbackHandler = $fallbackHandler;
        return $this;
    }

    /**
     * Executes the pipeline for the given request.
     */
    public function handle(Request $request): Response
    {
        return $this->dispatchMiddleware(0, $request);
    }

    /**
     * @internal
     */
    public function dispatchMiddleware(int $index, Request $request): Response
    {
        if (!isset($this->middlewares[$index])) {
            if ($this->fallbackHandler !== null) {
                return $this->fallbackHandler->handle($request);
            }
            return Response::empty(404);
        }

        $middleware = $this->middlewares[$index];
        $next = new class($this, $index + 1) implements RequestHandlerInterface {
            private Pipeline $pipeline;
            private int $nextIndex;

            public function __construct(Pipeline $pipeline, int $nextIndex)
            {
                $this->pipeline = $pipeline;
                $this->nextIndex = $nextIndex;
            }

            public function handle(Request $request): Response
            {
                return $this->pipeline->dispatchMiddleware($this->nextIndex, $request);
            }
        };

        return $middleware->process($request, $next);
    }
}
