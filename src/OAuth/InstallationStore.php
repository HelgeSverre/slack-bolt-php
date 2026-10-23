<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

use Closure;

interface InstallationStore
{
    public function save(Installation $installation): void;

    public function fetch(InstallationQuery $query): ?Installation;

    public function delete(InstallationQuery $query): void;

    /**
     * Runs the updater under the credential record's exclusive lock and writes
     * its return value before releasing the lock. Return null to delete it.
     *
     * @param  Closure(?Installation): ?Installation  $updater
     */
    public function update(InstallationQuery $query, Closure $updater): ?Installation;
}
