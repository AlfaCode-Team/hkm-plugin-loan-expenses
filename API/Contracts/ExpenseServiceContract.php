<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\API\Contracts;

/**
 * The published surface of the expenses domain.
 *
 * Every write takes a sync revision inside its transaction, so an expense
 * recorded on the web reaches the handsets on their next pull. Reports exclude
 * tombstoned rows and sum whole UGX.
 */
interface ExpenseServiceContract
{
    /**
     * @param  array<string, mixed> $filters  branch_id, category, payment_method,
     *         recorded_by, from, to (YYYY-MM-DD), page, per_page
     * @return array{data: list<array<string, mixed>>, page: int, per_page: int, total: int}
     */
    public function list(array $filters): array;

    /** @return array<string, mixed> */
    public function show(int $id): array;

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function record(array $input): array;

    /**
     * @param  array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public function update(int $id, array $changes): array;

    /** Tombstone an expense. The row stays so the deletion reaches the handsets. */
    public function delete(int $id): void;

    /**
     * Totals by category over an inclusive date range.
     *
     * @param  array<string, mixed> $filters  from, to (required), branch_id
     * @return array{from: string, to: string, grand_total: int, rows: list<array{category: string, group: ?string, total: int, count: int}>}
     */
    public function totalsByCategory(array $filters): array;

    /**
     * Totals by branch over an inclusive date range. A null branch means unassigned.
     *
     * @param  array<string, mixed> $filters  from, to (required)
     * @return array{from: string, to: string, grand_total: int, rows: list<array{branch_id: ?int, total: int, count: int}>}
     */
    public function totalsByBranch(array $filters): array;

    /**
     * One row per branch per day, over an inclusive date range.
     *
     * @param  array<string, mixed> $filters  from, to (required), branch_id
     * @return array{from: string, to: string, rows: list<array{spent_on: string, branch_id: ?int, total: int, count: int}>}
     */
    public function dailyTotalsByBranch(array $filters): array;
}
