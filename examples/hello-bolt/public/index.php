<?php

declare(strict_types=1);

use Tempest\Router\HttpApplication;
use Thunder\Bolt\Bootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

Bootstrap::boot(dirname(__DIR__))->get(HttpApplication::class)->run();
