<?php

declare(strict_types=1);

use Thunder\Bolt\Jobs\JobConfig;

use function Tempest\env;

$path = env('SLACK_JOB_STORE_PATH');
return new JobConfig(is_string($path) && $path !== '' ? $path : null);
