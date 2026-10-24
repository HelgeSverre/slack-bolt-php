<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

/** A failed durable intake must not become a successful transport acknowledgement. */
final class IntakeFailed extends \RuntimeException {}
