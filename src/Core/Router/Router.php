<?php

declare(strict_types=1);

namespace App\Core\Router;

use App\Core\Container\Container;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\Pipeline;
use App\Core\Middleware\RequestHandlerInterface;
use App\Domain\Exceptions\NotFoundException;
use Closure;
use ReflectionFunction;
use ReflectionMethod;

class Router implements RequestHandlerInterface
{
    /** @var list<array{method: string, path: string, pattern: string, params: list<string>, handler: callable|array|string, middlewares: list<mixed>}> */
    protected array $routes = [];

    protected string $currentPrefix = '';

    /** @var list<mixed> */
    protected array $groupMiddlewares = [];

    public function __construct(
        protected Container $container = new Container()
    ) {}

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Groups routes under a common URI prefix and optional middlewares.
     *
     * @param string $prefix
     * @param callable $callback
     * @param array $middlewares
     * @return self
     */
    public function group(string $prefix, callable $callback, array $middlewares = []): self
    {
        $previousPrefix = $this->currentPrefix;
        $previousMiddlewares = $this->groupMiddlewares;

        $prefix = '/' . trim($prefix, '/');
        $this->currentPrefix = $previousPrefix !== '' ? rtrim($previousPrefix, '/') . $prefix : $prefix;
        $this->groupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        $callback($this);

        $this->currentPrefix = $previousPrefix;
        $this->groupMiddlewares = $previousMiddlewares;

        return $this;
    }

    /**
     * Register a GET route.
     *
     * @param string $path
     * @param callable|array|string $handler
     * @param array $middlewares
     * @return self
     */
    public function get(string $path, $handler, array $middlewares = []): self
    {
        return $this->addRoute('GET', $path, $handler, $middlewares);
    }

    /**
     * Register a POST route.
     *
     * @param string $path
     * @param callable|array|string $handler
     * @param array $middlewares
     * @return self
     */
    public function post(string $path, $handler, array $middlewares = []): self
    {
        return $this->addRoute('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, $handler, array $middlewares = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $middlewares);
    }

    public function patch(string $path, $handler, array $middlewares = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $middlewares);
    }

    public function delete(string $path, $handler, array $middlewares = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middlewares);
    }

    /**
     * Adds an HTTP route with pattern parsing.
     *
     * @param string $method
     * @param string $path
     * @param callable|array|string $handler
     * @param array $middlewares
     * @return self
     */
    public function addRoute(string $method, string $path, $handler, array $middlewares = []): self
    {
        $fullPath = $path;
        if ($this->currentPrefix !== '') {
            $trimmedPath = trim($path, '/');
            $fullPath = $this->currentPrefix . ($trimmedPath !== '' ? '/' . $trimmedPath : '');
            if ($fullPath === '') {
                $fullPath = '/';
            }
        }

        $allMiddlewares = array_merge($this->groupMiddlewares, $middlewares);

        $params = [];
        $pattern = preg_replace_callback('/:([a-zA-Z0-9_]+)/', function ($matches) use (&$params) {
            $params[] = $matches[1];
            return '(?P<' . $matches[1] . '>[^/]+)';
        }, $fullPath);

        $pattern = '#^' . $pattern . '$#';

        $this->routes[] = [
            'method'      => strtoupper($method),
            'path'        => $fullPath,
            'pattern'     => $pattern,
            'params'      => $params,
            'handler'     => $handler,
            'middlewares' => $allMiddlewares,
        ];

        return $this;
    }

    /**
     * Handles the HTTP request by matching against defined routes.
     *
     * @throws NotFoundException when no route matches
     */
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                $namedParams = [];
                foreach ($route['params'] as $paramName) {
                    if (isset($matches[$paramName])) {
                        $namedParams[$paramName] = $matches[$paramName];
                    }
                }

                return $this->invokeRoute($route, $request, $namedParams);
            }
        }

        throw new NotFoundException('Endpoint no encontrado');
    }

    /**
     * Dispatches the incoming HTTP request.
     * Supports both modern ($request) and legacy signature ($method, $uri).
     *
     * @param Request|string $requestOrMethod
     * @param string|null $uri
     * @return Response
     */
    public function dispatch($requestOrMethod, ?string $uri = null): Response
    {
        if ($requestOrMethod instanceof Request) {
            return $this->handle($requestOrMethod);
        }

        $method = $requestOrMethod;
        $uri = $uri ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $request = new Request($method, $uri, $path, $_GET, [], []);

        try {
            $response = $this->handle($request);
        } catch (NotFoundException $e) {
            $response = Response::json($e->getPayload(), $e->getStatusCode());
        }

        $response->send();
        return $response;
    }

    /**
     * Invokes the matched route, passing through any route-level middlewares.
     *
     * @param array{method: string, path: string, pattern: string, params: list<string>, handler: callable|array|string, middlewares: list<mixed>} $route
     * @param array<string, string> $namedParams
     */
    protected function invokeRoute(array $route, Request $request, array $namedParams): Response
    {
        $handlerCallback = function (Request $req) use ($route, $namedParams): Response {
            return $this->executeHandler($route['handler'], $req, $namedParams);
        };

        if (empty($route['middlewares'])) {
            return $handlerCallback($request);
        }

        $pipeline = new Pipeline();

        foreach ($route['middlewares'] as $mw) {
            if ($mw instanceof MiddlewareInterface) {
                $pipeline->pipe($mw);
            } elseif (is_string($mw) && class_exists($mw)) {
                $resolved = $this->container->get($mw);
                if ($resolved instanceof MiddlewareInterface) {
                    $pipeline->pipe($resolved);
                } elseif (is_callable([$resolved, 'process'])) {
                    $pipeline->pipe($resolved);
                }
            } elseif (is_callable($mw)) {
                $pipeline->pipe(new class($mw) implements MiddlewareInterface {
                    private $callable;

                    public function __construct($callable)
                    {
                        $this->callable = $callable;
                    }

                    public function process(Request $request, RequestHandlerInterface $handler): Response
                    {
                        $res = ($this->callable)($request, $handler);
                        return $res instanceof Response ? $res : $handler->handle($request);
                    }
                });
            }
        }

        $pipeline->withFallback(new class($handlerCallback) implements RequestHandlerInterface {
            private Closure $callback;

            public function __construct(Closure $callback)
            {
                $this->callback = $callback;
            }

            public function handle(Request $request): Response
            {
                return ($this->callback)($request);
            }
        });

        return $pipeline->handle($request);
    }

    /**
     * Executes the controller or closure.
     *
     * @param callable|array|string $handler
     * @param Request $request
     * @param array<string, string> $namedParams
     * @return Response
     */
    protected function executeHandler($handler, Request $request, array $namedParams): Response
    {
        $result = null;

        if (is_array($handler)) {
            [$classOrObj, $action] = $handler;
            $controller = is_string($classOrObj) ? $this->container->get($classOrObj) : $classOrObj;

            $ref = new ReflectionMethod($controller, $action);
            $numParams = $ref->getNumberOfParameters();

            if ($numParams === 0) {
                $result = $controller->$action();
            } elseif ($numParams === 1) {
                $firstParam = $ref->getParameters()[0];
                if ($firstParam->getName() === 'params') {
                    $result = $controller->$action($namedParams);
                } else {
                    $result = $controller->$action($request);
                }
            } else {
                $result = $controller->$action($request, $namedParams);
            }
        } elseif ($handler instanceof Closure || is_callable($handler)) {
            $ref = new ReflectionFunction(Closure::fromCallable($handler));
            $numParams = $ref->getNumberOfParameters();

            if ($numParams === 0) {
                $result = $handler();
            } elseif ($numParams === 1) {
                $firstParam = $ref->getParameters()[0];
                if ($firstParam->getName() === 'params') {
                    $result = $handler($namedParams);
                } else {
                    $result = $handler($request);
                }
            } else {
                $result = $handler($request, $namedParams);
            }
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::empty(200);
    }
}
