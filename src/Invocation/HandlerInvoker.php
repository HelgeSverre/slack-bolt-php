<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Tempest\Container\Container;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\Routing\HandlerTarget;

final class HandlerInvoker
{
    /** @var array<string, array<string, 'payload'|'reply'|'delivery'|'client'|'authorization'|'authorizationSource'|'conversation'|'thread'>> */
    private array $argumentPlans = [];

    public function __construct(
        private Container $container,
    ) {}

    public function invoke(HandlerTarget $target, Delivery $delivery, ?Container $scope = null): mixed
    {
        $callable = $target->callable();
        if (is_array($callable)) {
            $callable = MethodReflector::fromParts($callable[0], $callable[1]);
        } elseif (is_string($callable) && function_exists($callable)) {
            $callable = Closure::fromCallable($callable);
        }

        return ($scope ?? $this->container)->invoke($callable, ...$this->deliveryArguments($target, $delivery));
    }

    /** @return array<string, object> */
    private function deliveryArguments(HandlerTarget $target, Delivery $delivery): array
    {
        $cacheKey = $target->key() . '|' . $delivery->payload::class;
        $plan = $this->argumentPlans[$cacheKey] ??= $this->argumentPlan($target, $delivery);
        $arguments = [];

        foreach ($plan as $name => $value) {
            $arguments[$name] = match ($value) {
                'payload' => $delivery->payload,
                'reply' => $delivery->reply,
                'delivery' => $delivery,
                'client' => $delivery->client ?? throw new \LogicException('No delivery client.'),
                'authorization' => $delivery->authorization ?? throw new \LogicException('No delivery authorization.'),
                'authorizationSource' => $delivery->authorizationSource ?? throw new \LogicException(
                    'No delivery authorization source.',
                ),
                'conversation' => $delivery->conversation ?? throw new \LogicException(
                    'No delivery conversation context.',
                ),
                'thread' => $delivery->thread ?? throw new \LogicException('No delivery thread context.'),
            };
        }

        return $arguments;
    }

    /** @return array<string, 'payload'|'reply'|'delivery'|'client'|'authorization'|'authorizationSource'|'conversation'|'thread'> */
    private function argumentPlan(HandlerTarget $target, Delivery $delivery): array
    {
        $plan = [];

        foreach ($this->reflect($target)->getParameters() as $parameter) {
            if ($this->accepts($parameter, $delivery->payload)) {
                $plan[$parameter->getName()] = 'payload';
            } elseif ($this->accepts($parameter, $delivery->reply)) {
                $plan[$parameter->getName()] = 'reply';
            } elseif ($this->accepts($parameter, $delivery)) {
                $plan[$parameter->getName()] = 'delivery';
            } elseif ($delivery->client !== null && $this->accepts($parameter, $delivery->client)) {
                $plan[$parameter->getName()] = 'client';
            } elseif ($delivery->authorization !== null && $this->accepts($parameter, $delivery->authorization)) {
                $plan[$parameter->getName()] = 'authorization';
            } elseif (
                $delivery->authorizationSource !== null
                && $this->accepts($parameter, $delivery->authorizationSource)
            ) {
                $plan[$parameter->getName()] = 'authorizationSource';
            } elseif ($delivery->conversation !== null && $this->accepts($parameter, $delivery->conversation)) {
                $plan[$parameter->getName()] = 'conversation';
            } elseif ($delivery->thread !== null && $this->accepts($parameter, $delivery->thread)) {
                $plan[$parameter->getName()] = 'thread';
            }
        }

        return $plan;
    }

    private function reflect(HandlerTarget $target): ReflectionFunctionAbstract
    {
        $callable = $target->callable();

        if ($callable instanceof Closure) {
            return new ReflectionFunction($callable);
        }

        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], $callable[1]);
        }

        if (class_exists($callable)) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction($callable);
    }

    private function accepts(ReflectionParameter $parameter, object $value): bool
    {
        return $this->typeAccepts($parameter->getType(), $value);
    }

    private function typeAccepts(?ReflectionType $type, object $value): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $this->matchesNamedType($type, $value);
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType && $this->matchesNamedType($candidate, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesNamedType(ReflectionNamedType $type, object $value): bool
    {
        if ($type->isBuiltin()) {
            return false;
        }

        $name = $type->getName();
        if (!class_exists($name) && !interface_exists($name)) {
            return false;
        }

        return is_a($value, $name);
    }
}
