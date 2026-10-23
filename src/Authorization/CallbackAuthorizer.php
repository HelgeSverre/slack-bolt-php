<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

use Closure;

final readonly class CallbackAuthorizer implements Authorizer
{
    /** @param Closure(AuthorizationSource): mixed $callback */
    public function __construct(
        private Closure $callback,
    ) {}

    /** @param callable(AuthorizationSource): mixed $callback */
    public static function from(callable $callback): self
    {
        return new self(Closure::fromCallable($callback));
    }

    public function authorize(AuthorizationSource $source): Authorization
    {
        $authorization = ($this->callback)($source);
        if (!$authorization instanceof Authorization) {
            throw new AuthorizationException('An authorization callback must return Authorization.');
        }

        return $authorization;
    }
}
