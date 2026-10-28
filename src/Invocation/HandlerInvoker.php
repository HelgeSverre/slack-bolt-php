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
    /** @var array<string, array<string, string>> */
    private array $argumentPlans = [];

    public function __construct(
        private Container $container,
    ) {}

    public function invoke(HandlerTarget $target, Delivery $delivery, ?Container $scope = null): mixed
    {
        $callable = $target->callable();
        if ($scope === null && is_array($callable) && $this->needsContext($callable[0])) {
            $scope = InvocationScope::create(
                $this->container,
                $delivery->client ?? throw new \LogicException('No delivery client.'),
                $delivery->authorization ?? throw new \LogicException('No delivery authorization.'),
            );
            $scope->singleton(\Thunder\Bolt\Context::class, $delivery->context);
        }
        if (is_array($callable)) {
            $callable = MethodReflector::fromParts($callable[0], $callable[1]);
        } elseif (is_string($callable) && function_exists($callable)) {
            $callable = Closure::fromCallable($callable);
        }

        return ($scope ?? $this->container)->invoke($callable, ...$this->deliveryArguments($target, $delivery));
    }

    private function needsContext(string $class, array $visited = []): bool
    {
        if ($class === \Thunder\Bolt\Context::class) {
            return true;
        }
        if (isset($visited[$class]) || !class_exists($class)) {
            return false;
        }
        $visited[$class] = true;
        foreach (new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
            foreach ($types as $dependency) {
                if (
                    $dependency instanceof ReflectionNamedType
                    && !$dependency->isBuiltin()
                    && $this->needsContext($dependency->getName(), $visited)
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string, object> */
    private function deliveryArguments(HandlerTarget $target, Delivery $delivery): array
    {
        $cacheKey =
            $target->key()
            . '|'
            . $delivery->payload::class
            . ($delivery->assistant === null ? '' : '|assistant')
            . ($delivery->execution === null ? '' : '|execution')
            . ($delivery->jobs === null ? '' : '|jobs');
        $plan = $this->argumentPlans[$cacheKey] ??= $this->argumentPlan($target, $delivery);
        $arguments = [];

        foreach ($plan as $name => $value) {
            if (str_starts_with($value, 'inputs:')) {
                $class = substr($value, 7);
                if (!class_exists($class) || $delivery->execution === null) {
                    throw new \LogicException('Mapped function inputs require an active function execution.');
                }
                $arguments[$name] = $delivery->execution->inputs()->as($class);
                continue;
            }
            $arguments[$name] = match ($value) {
                'payload' => $delivery->payload,
                'reply' => $delivery->reply,
                'delivery' => $delivery,
                'context' => $delivery->context,
                'jobs' => $delivery->jobs ?? throw new \LogicException('No delivery jobs capability.'),
                'client' => $delivery->client ?? throw new \LogicException('No delivery client.'),
                'authorization' => $delivery->authorization ?? throw new \LogicException('No delivery authorization.'),
                'authorizationSource' => $delivery->authorizationSource ?? throw new \LogicException(
                    'No delivery authorization source.',
                ),
                'conversation' => $delivery->conversation ?? throw new \LogicException(
                    'No delivery conversation context.',
                ),
                'thread' => $delivery->thread ?? throw new \LogicException('No delivery thread context.'),
                'assistant' => $delivery->assistant ?? throw new \LogicException('No delivery assistant thread.'),
                'execution' => $delivery->execution ?? throw new \LogicException('No function execution context.'),
                'functionInputs' => $delivery->execution?->inputs() ?? throw new \LogicException('No function inputs.'),
                'functionContext' => $delivery->execution?->context ?? throw new \LogicException(
                    'No function execution context.',
                ),
                default => throw new \LogicException('Unknown delivery argument.'),
            };
        }

        return $arguments;
    }

    /** @return array<string, string> */
    private function argumentPlan(HandlerTarget $target, Delivery $delivery): array
    {
        $plan = [];

        foreach ($this->reflect($target)->getParameters() as $parameter) {
            if ($parameter->getAttributes(\Thunder\Bolt\Attributes\FromInputs::class) !== []) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())) {
                    throw new \InvalidArgumentException('FromInputs parameters need a concrete input DTO class.');
                }
                if ($delivery->execution === null) {
                    throw new \LogicException('FromInputs requires an active function execution.');
                }
                $plan[$parameter->getName()] = 'inputs:' . $type->getName();
            } elseif ($this->accepts($parameter, $delivery->payload)) {
                $plan[$parameter->getName()] = 'payload';
            } elseif ($this->accepts($parameter, $delivery->reply)) {
                $plan[$parameter->getName()] = 'reply';
            } elseif ($this->accepts($parameter, $delivery)) {
                $plan[$parameter->getName()] = 'delivery';
            } elseif ($this->accepts($parameter, $delivery->context)) {
                $plan[$parameter->getName()] = 'context';
            } elseif ($delivery->jobs !== null && $this->accepts($parameter, $delivery->jobs)) {
                $plan[$parameter->getName()] = 'jobs';
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
            } elseif ($delivery->assistant !== null && $this->accepts($parameter, $delivery->assistant)) {
                $plan[$parameter->getName()] = 'assistant';
            } elseif ($delivery->execution !== null && $this->accepts($parameter, $delivery->execution)) {
                $plan[$parameter->getName()] = 'execution';
            } elseif ($delivery->execution !== null && $this->accepts($parameter, $delivery->execution->inputs())) {
                $plan[$parameter->getName()] = 'functionInputs';
            } elseif ($delivery->execution !== null && $this->accepts($parameter, $delivery->execution->context)) {
                $plan[$parameter->getName()] = 'functionContext';
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
