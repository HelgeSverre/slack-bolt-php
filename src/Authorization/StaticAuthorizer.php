<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

final readonly class StaticAuthorizer implements Authorizer
{
    public function __construct(
        private Authorization $authorization,
    ) {}

    public function authorize(AuthorizationSource $source): Authorization
    {
        return $this->authorization;
    }
}
