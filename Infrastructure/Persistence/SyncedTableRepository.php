<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * The write rules every synced table in this module shares. A copy of the
 * shape used by Loans, kept local so this module depends on no other domain's
 * internals.
 *
 * Every write takes the revision it is given and stamps the device and the
 * timestamps. A delete is a tombstone: `deleted_at` is set and the row stays.
 * Nothing here issues DELETE.
 */
abstract class SyncedTableRepository
{
    public function __construct(
        protected readonly DatabasePort $db,
    ) {
    }

    abstract protected function table(): string;

    /** @param array<string, mixed> $columns  including `id` */
    protected function insertRow(array $columns, int $rev, string $device, string $now): void
    {
        $values = $columns + [
            'rev'        => $rev,
            'device_id'  => $device,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];

        $names = array_keys($values);

        $this->db->execute(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table(),
                implode(', ', $names),
                implode(', ', array_map(static fn(string $n): string => ':' . $n, $names)),
            ),
            $values,
        );
    }

    /** @param array<string, mixed> $columns  WITHOUT `id` */
    protected function updateRow(int $id, array $columns, int $rev, string $device, string $now): void
    {
        $values = $columns + [
            'rev'        => $rev,
            'device_id'  => $device,
            'updated_at' => $now,
        ];

        $assignments = implode(', ', array_map(
            static fn(string $c): string => sprintf('%s = :%s', $c, $c),
            array_keys($values),
        ));

        $this->db->execute(
            sprintf('UPDATE %s SET %s WHERE id = :where_id', $this->table(), $assignments),
            $values + ['where_id' => $id],
        );
    }

    /** @param array<string, mixed> $params  must not reuse the names deleted, rev, device or now */
    protected function tombstoneWhere(string $where, array $params, int $rev, string $device, string $now): int
    {
        return $this->db->execute(
            sprintf(
                'UPDATE %s SET deleted_at = :deleted, rev = :rev, device_id = :device, updated_at = :now'
                . ' WHERE %s AND deleted_at IS NULL',
                $this->table(),
                $where,
            ),
            $params + ['deleted' => $now, 'rev' => $rev, 'device' => $device, 'now' => $now],
        );
    }

    /**
     * Turn a driver failure into this layer's exception, so no PDO exception escapes.
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    protected function guard(string $what, callable $work): mixed
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to ' . $what . '.',
                layer: 'repository.expenses',
                previous: $e,
            );
        }
    }
}
