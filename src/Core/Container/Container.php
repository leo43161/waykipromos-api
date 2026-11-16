<?php

declare(strict_types=1);

namespace App\Core\Container;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Bind an abstract type to a concrete implementation or factory closure.
     *
     * @param string $id
     * @param callable|string $concrete
     */
    public function bind(string $id, $concrete): void
    {
        $this->bindings[$id] = $concrete;
    }

    /**
     * Bind an instance as a singleton.
     *
     * @param string $id
     * @param callable|string|object $concrete
     */
    public function singleton(string $id, $concrete): void
    {
        if (is_object($concrete) && !($concrete instanceof \Closure)) {
            $this->instances[$id] = $concrete;
            return;
        }

        $this->bindings[$id] = function (self $c) use ($concrete) {
            static $instance = null;
            if ($instance === null) {
                $instance = is_callable($concrete) ? $concrete($c) : $c->make($concrete);
            }
            return $instance;
        };
    }

    /**
     * Set an existing object instance directly.
     *
     * @param string $id
     * @param mixed $instance
     */
    public function set(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * Checks if the container has a binding or instance for the given identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * Resolves an entry of the container by its identifier.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $concrete = $this->bindings[$id];
            if (is_callable($concrete)) {
                return $concrete($this);
            }
            if (is_string($concrete)) {
                return $this->make($concrete);
            }
            return $concrete;
        }

        if (class_exists($id)) {
            return $this->make($id);
        }

        throw new RuntimeException("No container binding found for [{$id}]");
    }

    /**
     * Automatically wires and instantiates a class using Reflection.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $parameters
     * @return T
     */
    public function make(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Target class [{$class}] does not exist");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Target class [{$class}] is not instantiable");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            // Si se suministró explícitamente en $parameters
            if (array_key_exists($name, $parameters)) {
                $dependencies[] = $parameters[$name];
                continue;
            }

            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                try {
                    $dependencies[] = $this->get($typeName);
                    continue;
                } catch (RuntimeException $e) {
                    if ($param->isDefaultValueAvailable()) {
                        $dependencies[] = $param->getDefaultValue();
                        continue;
                    }
                    if ($param->allowsNull()) {
                        $dependencies[] = null;
                        continue;
                    }
                    throw $e;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $dependencies[] = null;
            } else {
                throw new RuntimeException("Cannot resolve parameter [{$name}] for class [{$class}]");
            }
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
