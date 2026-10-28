<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

/** An HTTP outcome chosen by a host route; state cookies remain managed by Bolt. */
final readonly class InstallResponse
{
    private function __construct(
        public int $status,
        public string $body = '',
        public ?string $location = null,
    ) {}

    public static function text(string $body, int $status = 200): self
    {
        if (
            $status < 200
            || $status > 599
            || $status >= 300 && $status < 400
            || \Tempest\Http\Status::tryFrom($status) === null
        ) {
            throw new \InvalidArgumentException('Installation text responses require a non-redirect HTTP status.');
        }
        return new self($status, $body);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        $parts = parse_url($location);
        $relative = str_starts_with($location, '/') && !str_starts_with($location, '//');
        $https =
            is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
        if (
            !$relative && !$https
            || preg_match('/[\x00-\x20\x7f\\\\]/', $location) === 1
            || !in_array($status, [302, 303, 307, 308], true)
        ) {
            throw new \InvalidArgumentException(
                'Installation redirects require an HTTPS URL or a local absolute path.',
            );
        }
        return new self($status, location: $location);
    }
}
