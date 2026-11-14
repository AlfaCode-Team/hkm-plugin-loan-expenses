<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Infrastructure\Persistence;

/**
 * The `expenses` table.
 *
 * Every read excludes tombstones: a deleted expense is absent from listings,
 * lookups and every total alike. Dates are compared as bound YYYY-MM-DD strings,
 * which orders correctly on SQLite (TEXT) and MySQL/PostgreSQL (DATE), so no
 * driver-specific date function is needed. Sums are SQL SUM over an integer
 * column and are cast to int, never computed as floats.
 */
final class ExpenseRepository extends SyncedTableRepository
{
    private const string LIVE = 'deleted_at IS NULL';

    protected function table(): string
    {
        return 'expenses';
    }

    /** @return array<string, mixed>|null  live rows only */
    public function find(int $id): ?array
    {
        return $this->guard('read expense', fn(): ?array => $this->db->queryOne(
            'SELECT * FROM expenses WHERE id = :id AND ' . self::LIVE,
            ['id' => $id],
        ));
    }

    /**
     * @param  array{branch_id: ?int, category: ?string, payment_method: ?string, recorded_by: ?int, from: ?string, to: ?string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->criteria($filters);

        return $this->guard('list expenses', function () use ($where, $params, $limit, $offset): array {
            $total = $this->db->queryOne('SELECT COUNT(*) AS total FROM expenses WHERE ' . $where, $params);

            // The paging clause comes from the dialect: SQL Server has no LIMIT,
            // and no driver here accepts a bound one.
            $rows = $this->db->query(
                sprintf(
                    'SELECT * FROM expenses WHERE %s ORDER BY spent_on DESC, id DESC%s',
                    $where,
                    SqlDialect::of($this->db)->page(max(1, $limit), max(0, $offset)),
                ),
                $params,
            );

            return ['rows' => $rows, 'total' => (int) ($total['total'] ?? 0)];
        });
    }

    /**
     * Totals grouped by category over an inclusive date range.
     *
     * @param  array{branch_id: ?int, from: string, to: string} $filters
     * @return list<array{category: string, total: int, count: int}>
     */
    public function totalsByCategory(array $filters): array
    {
        [$where, $params] = $this->rangeCriteria($filters);

        return $this->guard('total expenses by category', function () use ($where, $params): array {
            $rows = $this->db->query(
                'SELECT category, SUM(amount) AS total, COUNT(*) AS n FROM expenses WHERE ' . $where
                . ' GROUP BY category ORDER BY total DESC, category ASC',
                $params,
            );

            return array_map(static fn(array $r): array => [
                'category' => (string) $r['category'],
                'total'    => (int) $r['total'],
                'count'    => (int) $r['n'],
            ], $rows);
        });
    }

    /**
     * Totals grouped by branch over an inclusive date range. A null branch_id is its own group.
     *
     * @param  array{from: string, to: string} $filters
     * @return list<array{branch_id: ?int, total: int, count: int}>
     */
    public function totalsByBranch(array $filters): array
    {
        [$where, $params] = $this->rangeCriteria($filters);

        return $this->guard('total expenses by branch', function () use ($where, $params): array {
            $rows = $this->db->query(
                'SELECT branch_id, SUM(amount) AS total, COUNT(*) AS n FROM expenses WHERE ' . $where
                . ' GROUP BY branch_id ORDER BY total DESC, branch_id ASC',
                $params,
            );

            return array_map(static fn(array $r): array => [
                'branch_id' => $r['branch_id'] === null ? null : (int) $r['branch_id'],
                'total'     => (int) $r['total'],
                'count'     => (int) $r['n'],
            ], $rows);
        });
    }

    /**
     * One row per branch per day over an inclusive date range.
     *
     * @param  array{from: string, to: string, branch_id: ?int} $filters
     * @return list<array{spent_on: string, branch_id: ?int, total: int, count: int}>
     */
    public function dailyTotalsByBranch(array $filters): array
    {
        [$where, $params] = $this->rangeCriteria($filters);

        return $this->guard('total daily expenses', function () use ($where, $params): array {
            $rows = $this->db->query(
                'SELECT spent_on, branch_id, SUM(amount) AS total, COUNT(*) AS n FROM expenses WHERE ' . $where
                . ' GROUP BY spent_on, branch_id ORDER BY spent_on ASC, branch_id ASC',
                $params,
            );

            return array_map(static fn(array $r): array => [
                'spent_on'  => substr((string) $r['spent_on'], 0, 10),
                'branch_id' => $r['branch_id'] === null ? null : (int) $r['branch_id'],
                'total'     => (int) $r['total'],
                'count'     => (int) $r['n'],
            ], $rows);
        });
    }

    /** @param array<string, mixed> $row  including `id` */
    public function insert(array $row, int $rev, string $device, string $now): void
    {
        $this->guard('insert expense', fn() => $this->insertRow($row, $rev, $device, $now));
    }

    /** @param array<string, mixed> $row  WITHOUT `id` */
    public function update(int $id, array $row, int $rev, string $device, string $now): void
    {
        $this->guard('update expense', fn() => $this->updateRow($id, $row, $rev, $device, $now));
    }

    public function tombstone(int $id, int $rev, string $device, string $now): void
    {
        $this->guard('delete expense', fn() => $this->tombstoneWhere('id = :id', ['id' => $id], $rev, $device, $now));
    }

    /**
     * @param  array{branch_id: ?int, category: ?string, payment_method: ?string, recorded_by: ?int, from: ?string, to: ?string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function criteria(array $filters): array
    {
        $where  = [self::LIVE];
        $params = [];

        if ($filters['branch_id'] !== null) {
            $where[]             = 'branch_id = :branch_id';
            $params['branch_id'] = $filters['branch_id'];
        }
        if ($filters['category'] !== null) {
            $where[]            = 'category = :category';
            $params['category'] = $filters['category'];
        }
        if ($filters['payment_method'] !== null) {
            $where[]                  = 'payment_method = :payment_method';
            $params['payment_method'] = $filters['payment_method'];
        }
        if ($filters['recorded_by'] !== null) {
            $where[]               = 'recorded_by = :recorded_by';
            $params['recorded_by'] = $filters['recorded_by'];
        }
        if ($filters['from'] !== null) {
            $where[]         = 'spent_on >= :from';
            $params['from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $where[]       = 'spent_on <= :to';
            $params['to'] = $filters['to'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param  array{from: string, to: string, branch_id?: ?int} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function rangeCriteria(array $filters): array
    {
        $where  = [self::LIVE, 'spent_on >= :from', 'spent_on <= :to'];
        $params = ['from' => $filters['from'], 'to' => $filters['to']];

        if (($filters['branch_id'] ?? null) !== null) {
            $where[]             = 'branch_id = :branch_id';
            $params['branch_id'] = $filters['branch_id'];
        }

        return [implode(' AND ', $where), $params];
    }
}
