<?php

declare(strict_types=1);

namespace App\Demo\Pet;

/**
 * @phpstan-type Contributor array{actions: int, xp: int, last_action_at: int}
 * @phpstan-type AuditEvent array{actor: string, action: string, at: int, outcome: string}
 * @phpstan-type Team array{name: string, fullness: int, joy: int, cleanliness: int, energy: int, xp: int, last_updated_at: int, contributors: array<string, Contributor>, deliveries: array<string, int>, audit: list<AuditEvent>, latest_action: AuditEvent|null}
 * @phpstan-type Document array{version: 1, teams: array<string, Team>}
 * @phpstan-type PublicState array{name: string, stats: array{fullness: int, joy: int, cleanliness: int, energy: int}, xp: int, level: int, evolution: string, condition: string, contributors: array<string, Contributor>, latest_action: AuditEvent|null, audit: list<AuditEvent>, outcome: string, cooldown_seconds: int}
 */
final class PetStore
{
    private const int COOLDOWN_SECONDS = 15;
    private const int AUDIT_LIMIT = 25;
    private const int DELIVERY_LIMIT = 200;
    private const int DECAY_INTERVAL_SECONDS = 900;

    /** @var \Closure(): int|null */
    private readonly ?\Closure $clock;

    public function __construct(
        private readonly string $path,
        ?callable $clock = null,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('A pet store path is required.');
        }

        $this->clock = $clock === null ? null : static fn(): int => (int) $clock();
    }

    /** @return PublicState */
    public function snapshot(string $teamId): array
    {
        self::identifier($teamId, 'team ID');

        /** @var PublicState $state */
        $state = $this->locked(
            /** @param Document $document @return array{Document, PublicState, bool} */ function (array $document) use (
                $teamId,
            ): array {
                $now = $this->now();
                $created = !isset($document['teams'][$teamId]);
                /** @var Team $team */
                $team = $this->team($document, $teamId, $now);
                $changed = $this->decay($team, $now);
                $document['teams'][$teamId] = $team;

                return [$document, $this->publicState($team, 'snapshot'), $changed || $created];
            },
        );

        return $state;
    }

    /** @return PublicState */
    public function care(string $teamId, string $userId, string $action, string $deliveryId): array
    {
        self::identifier($teamId, 'team ID');
        self::identifier($userId, 'user ID');
        self::identifier($deliveryId, 'delivery ID');
        if (!in_array($action, ['feed', 'play', 'clean', 'rest'], true)) {
            throw new \InvalidArgumentException('Pet care must be feed, play, clean, or rest.');
        }

        /** @var PublicState $state */
        $state = $this->locked(
            /** @param Document $document @return array{Document, PublicState, bool} */ function (array $document) use (
                $teamId,
                $userId,
                $action,
                $deliveryId,
            ): array {
                $now = $this->now();
                $created = !isset($document['teams'][$teamId]);
                /** @var Team $team */
                $team = $this->team($document, $teamId, $now);
                $changed = $this->decay($team, $now);

                if (isset($team['deliveries'][$deliveryId])) {
                    $document['teams'][$teamId] = $team;

                    return [$document, $this->publicState($team, 'idempotent'), $changed || $created];
                }

                $previous = $team['contributors'][$userId]['last_action_at'] ?? null;
                if (is_int($previous) && ($now - $previous) < self::COOLDOWN_SECONDS) {
                    $team['deliveries'][$deliveryId] = $now;
                    $this->trimDeliveries($team);
                    $team['latest_action'] = [
                        'actor' => $userId,
                        'action' => $action,
                        'at' => $now,
                        'outcome' => 'cooldown',
                    ];
                    $document['teams'][$teamId] = $team;

                    return [$document, $this->publicState($team, 'cooldown'), true];
                }

                $this->applyCare($team, $action, $userId, $now, $deliveryId);
                $document['teams'][$teamId] = $team;

                return [$document, $this->publicState($team, 'cared'), true];
            },
        );

        return $state;
    }

    /** @return array<string, mixed> */
    private function locked(callable $operation): array
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException("Cannot create pet store directory: {$directory}");
            }
            chmod($directory, 0775);
        }

        $lock = fopen($this->path . '.lock', 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the pet store lock.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock the pet store.');
            }

            $document = $this->read();
            $result = $operation($document);
            if (
                !is_array($result)
                || !is_array($result[0] ?? null)
                || !is_array($result[1] ?? null)
                || !is_bool($result[2] ?? null)
            ) {
                throw new \RuntimeException('The pet store transaction returned an invalid result.');
            }
            /** @var Document $document */
            $document = $result[0];
            /** @var array<string, mixed> $state */
            $state = $result[1];
            $write = $result[2];
            if ($write) {
                $this->write($document);
            }

            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return Document */
    private function read(): array
    {
        if (!file_exists($this->path)) {
            return ['version' => 1, 'teams' => []];
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            throw new \RuntimeException('Cannot read the pet store.');
        }
        try {
            /** @var Document $document */
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The pet store JSON is corrupt; it was left untouched.', previous: $exception);
        }
        if (!is_array($document) || $document['version'] !== 1 || !is_array($document['teams'] ?? null)) {
            throw new \RuntimeException('The pet store schema is invalid; it was left untouched.');
        }

        return $document;
    }

    /** @param Document $document */
    private function write(array $document): void
    {
        try {
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot encode the pet store.', previous: $exception);
        }

        $temporary = tempnam(dirname($this->path), basename($this->path) . '.');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot prepare an atomic pet store update.');
        }
        $file = fopen($temporary, 'wb');
        if ($file === false) {
            unlink($temporary);
            throw new \RuntimeException('Cannot write an atomic pet store update.');
        }

        try {
            if (fwrite($file, $json . PHP_EOL) === false || !fflush($file)) {
                throw new \RuntimeException('Cannot flush the pet store update.');
            }
            if (function_exists('fsync') && !fsync($file)) {
                throw new \RuntimeException('Cannot synchronize the pet store update.');
            }
        } finally {
            fclose($file);
        }

        chmod($temporary, 0600);
        if (!rename($temporary, $this->path)) {
            unlink($temporary);
            throw new \RuntimeException('Cannot replace the pet store atomically.');
        }
    }

    /** @param Document $document @return Team */
    private function team(array $document, string $teamId, int $now): array
    {
        if (!isset($document['teams'][$teamId])) {
            return [
                'name' => 'Boltling',
                'fullness' => 72,
                'joy' => 68,
                'cleanliness' => 70,
                'energy' => 76,
                'xp' => 0,
                'last_updated_at' => $now,
                'contributors' => [],
                'deliveries' => [],
                'audit' => [],
                'latest_action' => null,
            ];
        }

        $team = $document['teams'][$teamId];
        if (!is_array($team) || !is_int($team['last_updated_at'] ?? null) || !is_int($team['xp'] ?? null)) {
            throw new \RuntimeException('The pet state is invalid; it was left untouched.');
        }
        foreach (['fullness', 'joy', 'cleanliness', 'energy'] as $stat) {
            if (!is_int($team[$stat] ?? null) || $team[$stat] < 0 || $team[$stat] > 100) {
                throw new \RuntimeException('The pet state contains invalid stats; it was left untouched.');
            }
        }
        foreach (['contributors', 'deliveries', 'audit'] as $key) {
            if (!is_array($team[$key] ?? null)) {
                throw new \RuntimeException('The pet state is invalid; it was left untouched.');
            }
        }

        return $team;
    }

    /** @param Team $team */
    private function decay(array &$team, int $now): bool
    {
        $elapsed = max(0, $now - $team['last_updated_at']);
        $ticks = intdiv($elapsed, self::DECAY_INTERVAL_SECONDS);
        if ($ticks === 0) {
            return false;
        }

        $team['fullness'] = max(0, $team['fullness'] - ($ticks * 5));
        $team['joy'] = max(0, $team['joy'] - ($ticks * 3));
        $team['cleanliness'] = max(0, $team['cleanliness'] - ($ticks * 2));
        $team['energy'] = max(0, $team['energy'] - ($ticks * 2));
        $team['last_updated_at'] += $ticks * self::DECAY_INTERVAL_SECONDS;

        return true;
    }

    /** @param Team $team */
    private function applyCare(array &$team, string $action, string $userId, int $now, string $deliveryId): void
    {
        $effects = match ($action) {
            'feed' => ['fullness' => 24, 'joy' => 4, 'energy' => -2],
            'play' => ['joy' => 22, 'energy' => -14, 'fullness' => -5],
            'clean' => ['cleanliness' => 25, 'joy' => 5],
            'rest' => ['energy' => 25, 'joy' => 3, 'fullness' => -2],
            default => throw new \LogicException('Unsupported pet care action.'),
        };
        foreach ($effects as $stat => $amount) {
            $team[$stat] = min(100, max(0, $team[$stat] + $amount));
        }
        $team['last_updated_at'] = $now;
        $team['xp'] += 12;
        $team['contributors'][$userId] = [
            'actions' => (int) ($team['contributors'][$userId]['actions'] ?? 0) + 1,
            'xp' => (int) ($team['contributors'][$userId]['xp'] ?? 0) + 12,
            'last_action_at' => $now,
        ];
        $team['deliveries'][$deliveryId] = $now;
        $this->trimDeliveries($team);
        $event = ['actor' => $userId, 'action' => $action, 'at' => $now, 'outcome' => 'cared'];
        $team['audit'][] = $event;
        $team['audit'] = array_slice($team['audit'], -self::AUDIT_LIMIT);
        $team['latest_action'] = $event;
    }

    /** @param Team $team */
    private function trimDeliveries(array &$team): void
    {
        if (count($team['deliveries']) <= self::DELIVERY_LIMIT) {
            return;
        }
        asort($team['deliveries'], SORT_NUMERIC);
        $team['deliveries'] = array_slice($team['deliveries'], -self::DELIVERY_LIMIT, null, true);
    }

    /** @param Team $team @return PublicState */
    private function publicState(array $team, string $outcome): array
    {
        $level = 1 + intdiv($team['xp'], 100);
        $average = intdiv($team['fullness'] + $team['joy'] + $team['cleanliness'] + $team['energy'], 4);
        $contributors = $team['contributors'];
        uasort($contributors, static fn(array $left, array $right): int => $right['xp'] <=> $left['xp']);

        return [
            'name' => $team['name'],
            'stats' => [
                'fullness' => $team['fullness'],
                'joy' => $team['joy'],
                'cleanliness' => $team['cleanliness'],
                'energy' => $team['energy'],
            ],
            'xp' => $team['xp'],
            'level' => $level,
            'evolution' => match (true) {
                $level >= 10 => 'Storm Guardian',
                $level >= 6 => 'Thunder Pup',
                $level >= 3 => 'Sparkling',
                default => 'Hatchling',
            },
            'condition' => match (true) {
                $average === 0 => 'sleeping, but recoverable',
                $average < 35 => 'needs care',
                default => 'thriving',
            },
            'contributors' => array_slice($contributors, 0, 5, true),
            'latest_action' => $team['latest_action'],
            'audit' => $team['audit'],
            'outcome' => $outcome,
            'cooldown_seconds' => self::COOLDOWN_SECONDS,
        ];
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private static function identifier(string $value, string $field): void
    {
        if ($value === '' || mb_strlen($value) > 255) {
            throw new \InvalidArgumentException("A {$field} needs between 1 and 255 characters.");
        }
    }
}
