<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Client-minted ids for rows the web creates.
 *
 * Ids are minted from the legacy shard-0 range, below 1,000,000, as the MAX in
 * that range plus one. Tombstoned rows count, so an id is never reissued. Must
 * be called inside the transaction that writes the row it reserves.
 */
final class LegacyIdAllocator
{
    /**
     * THE SERVER'S OWN ID WINDOW.
     *
     * Ids are partitioned so that no two minters can collide without
     * coordinating: shard 0 is the LEGACY range (pre-sharding rows, and
     * everything the Firestore import brings over), shards 1..999_998 belong
     * to handsets, and shard 999_999 is the server. Web rows are minted here.
     *
     * They used to be minted in the LEGACY range as MAX(id)+1 — the same range
     * the import writes into. Create a loan on the web today, import a v1 loan
     * carrying that id next week, and one silently overwrites the other.
     *
     * Mirrors ShardClaim::SERVER_FLOOR / SERVER_CEILING in the Sync module.
     * Duplicated rather than imported, because a module may not reach into
     * another module's Domain.
     */
    private const int SERVER_FLOOR   = 999999000000;
    private const int SERVER_CEILING = 999999999999;

    private const array TABLES = ['expenses'];

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** @throws RepositoryException when shard 0 has no room left */
    public function reserve(string $table, int $count = 1): int
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new RepositoryException('Unknown table for id allocation.', layer: 'repository.expenses.ids');
        }

        try {
            $row = $this->db->queryOne(
                sprintf('SELECT COALESCE(MAX(id), 0) AS top FROM %s WHERE id >= :floor AND id <= :ceiling', $table),
                ['floor' => self::SERVER_FLOOR, 'ceiling' => self::SERVER_CEILING],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to read the id range.', layer: 'repository.expenses.ids', previous: $e);
        }

        $first = max((int) ($row['top'] ?? 0) + 1, self::SERVER_FLOOR);

        if ($first + $count - 1 > self::SERVER_CEILING) {
            throw new RepositoryException(
                'The legacy id range for ' . $table . ' is exhausted.',
                layer: 'repository.expenses.ids',
            );
        }

        return $first;
    }
}
