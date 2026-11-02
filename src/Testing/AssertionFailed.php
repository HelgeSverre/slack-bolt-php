<?php

declare(strict_types=1);

namespace Thunder\Bolt\Testing;

/** Works with any test runner; the toolkit does not require PHPUnit at runtime. */
final class AssertionFailed extends \RuntimeException {}
