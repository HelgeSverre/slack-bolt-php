<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

/** Immutable options for one installation. Metadata never travels through Slack. */
final readonly class InstallRequest
{
    /** @var list<string>|null */
    public ?array $scopes;
    /** @var list<string>|null */
    public ?array $userScopes;
    public array $metadata;

    /** @param list<string>|null $scopes
     * @param list<string>|null $userScopes
     */
    public function __construct(
        ?array $scopes = null,
        ?array $userScopes = null,
        public ?string $team = null,
        array $metadata = [],
    ) {
        OAuthConfig::validateScopes($scopes ?? []);
        OAuthConfig::validateScopes($userScopes ?? []);
        $this->scopes = $scopes === null ? null : array_map(static fn(string $scope): string => $scope, $scopes);
        $this->userScopes = $userScopes === null
            ? null
            : array_map(static fn(string $scope): string => $scope, $userScopes);
        if ($team !== null && preg_match('/\A[TE][A-Z0-9]+\z/', $team) !== 1) {
            throw new \InvalidArgumentException(
                'An installation workspace hint must be a Slack team or enterprise ID.',
            );
        }
        /** @var list<array{mixed, int}> $pending */
        $pending = [[$metadata, 0]];
        while ($pending !== []) {
            [$value, $depth] = array_pop($pending);
            if ($depth > 32) {
                throw new \InvalidArgumentException('Installation metadata may not exceed 32 nested levels.');
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pending[] = [$item, $depth + 1];
                }
            } elseif (!is_null($value) && !is_scalar($value)) {
                throw new \InvalidArgumentException('Installation metadata must contain only JSON values.');
            }
        }
        $encoded = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > 65_536) {
            throw new \InvalidArgumentException('Installation metadata may not exceed 64 KiB.');
        }
        // Detach references supplied by the caller before persisting a request.
        $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Installation metadata must be an array.');
        }
        $this->metadata = $decoded;
    }

    public static function make(): self
    {
        return new self();
    }

    public function scopes(string ...$scopes): self
    {
        return new self(array_values($scopes), $this->userScopes, $this->team, $this->metadata);
    }

    public function userScopes(string ...$scopes): self
    {
        return new self($this->scopes, array_values($scopes), $this->team, $this->metadata);
    }

    public function team(string $team): self
    {
        return new self($this->scopes, $this->userScopes, $team, $this->metadata);
    }

    public function metadata(array $metadata): self
    {
        return new self($this->scopes, $this->userScopes, $this->team, $metadata);
    }

    public function toArray(): array
    {
        return [
            'scopes' => $this->scopes,
            'userScopes' => $this->userScopes,
            'team' => $this->team,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $record): self
    {
        $scopes = $record['scopes'] ?? null;
        $userScopes = $record['userScopes'] ?? null;
        $team = $record['team'] ?? null;
        $metadata = $record['metadata'] ?? [];
        if (
            $scopes !== null && !is_array($scopes)
            || $userScopes !== null && !is_array($userScopes)
            || $team !== null && !is_string($team)
            || !is_array($metadata)
        ) {
            throw new \InvalidArgumentException('Invalid stored installation request.');
        }
        OAuthConfig::validateScopes($scopes ?? []);
        OAuthConfig::validateScopes($userScopes ?? []);
        /** @var list<string>|null $scopes */
        /** @var list<string>|null $userScopes */
        return new self($scopes, $userScopes, $team, $metadata);
    }

    public function __debugInfo(): array
    {
        return [
            'scopes' => $this->scopes,
            'userScopes' => $this->userScopes,
            'team' => $this->team,
            'metadata' => '[redacted]',
        ];
    }
}
