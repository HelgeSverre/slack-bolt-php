<?php

declare(strict_types=1);

use Thunder\Bolt\Runtime\RuntimeMonitor;

return new RuntimeMonitor(dirname(__DIR__) . '/.thunder-bolt/state/runtime');
