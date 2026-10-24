<?php

declare(strict_types=1);

namespace App\Demo\Functions;

/** A protected demo execution cannot safely be run again without inspection. */
final class FunctionExecutionUnavailable extends \RuntimeException {}
