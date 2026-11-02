<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

use PDO;
use Thunder\Bolt\Jobs\JobSchema;

/** Reads an existing queue without creating, migrating, recovering or claiming work. */
final class QueueSnapshot
{
    /** @return array{available: bool, reason?: string, counts?: array<string, int>, due?: int, scheduled?: int, oldest_due_seconds?: ?int, expired_leases?: int} */
    public static function read(string $path, int $now): array
    {
        if (!is_file($path))
            return ['available' => false, 'reason' => 'not_initialized'];
        if (is_link($path))
            throw new \RuntimeException('Use the real queue path.');
        $connection = new PDO('sqlite:' . $path, options: [\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY]);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->exec('PRAGMA busy_timeout = 250');
        if (JobSchema::version($connection) !== JobSchema::VERSION)
            return ['available' => false, 'reason' => 'migration_required'];
        $connection->beginTransaction();
        $statement = $connection->query('SELECT status, COUNT(*) AS total FROM thunder_bolt_jobs GROUP BY status');
        if ($statement === false)
            throw new \RuntimeException('Cannot read queue counts.');
        $counts = array_fill_keys(['pending', 'running', 'succeeded', 'failed', 'cancelled', 'uncertain'], 0);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !array_key_exists((string) $row['status'], $counts))
                throw new \RuntimeException('Unrecognized queue status.');
            $counts[(string) $row['status']] = (int) $row['total'];
        }
        $query = $connection->prepare(<<<'SQL'
            SELECT
                SUM(CASE WHEN status = 'pending' AND available_at <= :now THEN 1 ELSE 0 END) AS due,
                MIN(CASE WHEN status = 'pending' AND available_at <= :now THEN available_at END) AS oldest_due,
                SUM(CASE WHEN status = 'running' AND lease_expires_at <= :now THEN 1 ELSE 0 END) AS expired_leases
            FROM thunder_bolt_jobs
            SQL);
        if ($query === false)
            throw new \RuntimeException('Cannot prepare queue snapshot.');
        $query->execute(['now' => $now]);
        $timing = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($timing))
            throw new \RuntimeException('Cannot read queue snapshot.');
        $connection->rollBack();
        return [
            'available' => true,
            'counts' => $counts,
            'due' => (int) $timing['due'],
            'scheduled' => $counts['pending'] - (int) $timing['due'],
            'oldest_due_seconds' => $timing['oldest_due'] === null ? null : max(0, $now - (int) $timing['oldest_due']),
            'expired_leases' => (int) $timing['expired_leases'],
        ];
    }
}
