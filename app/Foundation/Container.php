<?php

declare(strict_types=1);

namespace App\Foundation;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class Container
{
    private array $bindings = [];

    private array $instances = [];

    private array $aliases = [];

    public function bind(string $id, $resolver, bool $shared = false): void
    {
        $this->bindings[$id] = ['resolver' => $resolver, 'shared' => $shared];
        unset($this->instances[$id]);
    }

    public function singleton(string $id, $resolver = null): void
    {
        if ($resolver === null) {
            $resolver = $id;
        }

        $this->bind($id, $resolver, true);
    }

    public function instance(string $id, $instance): void
    {
        unset($this->bindings[$id]);
        $this->instances[$id] = $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function get(string $id)
    {
        if (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            $resolver = $binding['resolver'];

            if ($resolver instanceof Closure) {
                $object = $resolver($this);
            } elseif (is_string($resolver)) {
                $object = $this->build($resolver);
            } else {
                $object = $resolver;
            }

            if ($binding['shared']) {
                $this->instances[$id] = $object;
            }

            return $object;
        }

        return $this->build($id);
    }

    public function build(string $class)
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("Cannot resolve non-instantiable class [{$class}].");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $dependencies = array_map(
            fn (ReflectionParameter $param) => $this->resolveParameter($param, $class),
            $constructor->getParameters()
        );

        return $reflection->newInstanceArgs($dependencies);
    }

    public function call($callable, array $parameters = [])
    {
        if (is_array($callable) && count($callable) === 2) {
            $object = $callable[0];

            if (is_string($object)) {
                $object = $this->get($object);
            }

            $reflection = new ReflectionMethod($object, $callable[1]);
            $args = $this->resolveArgs($reflection, $parameters);

            return $reflection->invokeArgs($object, $args);
        }

        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable, 2);

            return $this->call([$class, $method], $parameters);
        }

        $reflection = new ReflectionFunction($callable);
        $args = $this->resolveArgs($reflection, $parameters);

        return $reflection->invokeArgs($args);
    }

    private function resolveArgs(\ReflectionFunctionAbstract $reflection, array $parameters): array
    {
        $args = [];

        foreach ($reflection->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $parameters)) {
                $args[] = $parameters[$name];
                continue;
            }

            $args[] = $this->resolveParameter($param);
        }

        return $args;
    }

    public function make(string $class, array $parameters = [])
    {
        return $this->build($class);
    }

    private function resolveParameter(ReflectionParameter $param, ?string $context = null)
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $name = $type->getName();

            if (isset($this->instances[$name]) || isset($this->bindings[$name]) || class_exists($name)) {
                return $this->get($name);
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        $where = $context ? " in [{$context}]" : '';
        throw new \RuntimeException("Unable to resolve dependency [{$param->getName()}]{$where}.");
    }
}
