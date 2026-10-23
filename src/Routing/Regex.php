<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

use Thunder\Bolt\Exceptions\InvalidListener;

final readonly class Regex
{
    public static function of(string $pattern): self
    {
        return new self($pattern);
    }

    public function __construct(
        public string $pattern,
    ) {
        // @mago-expect lint:no-error-control-operator -- Invalid PCRE patterns intentionally emit warnings.
        if (@preg_match($pattern, '') === false) {
            throw InvalidListener::invalidRegex($pattern);
        }
    }

    public function matches(string $subject): bool
    {
        return $this->captures($subject) !== null;
    }

    /** @return array<int|string, string>|null */
    public function captures(string $subject): ?array
    {
        $captures = [];

        return preg_match($this->pattern, $subject, $captures) === 1 ? $captures : null;
    }
}
