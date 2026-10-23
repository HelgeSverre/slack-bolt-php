<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth\Stores;

use Closure;
use Thunder\Bolt\OAuth\Installation;
use Thunder\Bolt\OAuth\InstallationQuery;
use Thunder\Bolt\OAuth\InstallationStore;

/** In-process store intended for tests and ephemeral development. */
final class MemoryInstallationStore implements InstallationStore
{
    /** @var array<string, Installation> */
    private array $records = [];

    public function save(Installation $installation): void
    {
        if ($installation->botToken !== null) {
            $this->records[$this->key($installation->query()->forBot())] = $installation;
        }
        if ($installation->userId !== null && $installation->userToken !== null) {
            $this->records[$this->key($installation->query()->forUser($installation->userId))] = $installation;
        }
    }

    public function fetch(InstallationQuery $query): ?Installation
    {
        return $this->records[$this->key($query)] ?? null;
    }

    public function delete(InstallationQuery $query): void
    {
        if ($query->userId !== null) {
            unset($this->records[$this->key($query)]);

            return;
        }

        $prefix = $query->scopeKey() . '|';
        foreach (array_keys($this->records) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->records[$key]);
            }
        }
    }

    public function update(InstallationQuery $query, Closure $updater): ?Installation
    {
        $updated = $updater($this->fetch($query));
        if ($updated === null) {
            $this->delete($query);

            return null;
        }

        $this->records[$this->key($query)] = $updated;

        return $updated;
    }

    private function key(InstallationQuery $query): string
    {
        return $query->scopeKey() . '|' . ($query->userId === null ? 'bot' : 'user:' . $query->userId);
    }
}
