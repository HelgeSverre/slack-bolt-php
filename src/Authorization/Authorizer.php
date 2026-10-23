<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

interface Authorizer
{
    public function authorize(AuthorizationSource $source): Authorization;
}
