<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanExpenses;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\LoanExpenses\Application\Services\ExpenseService;
use Tests\Unit\Plugins\LoanExpenses\Support\ExpenseHarness;

#[CoversClass(ExpenseService::class)]
final class ExpenseServiceTest extends TestCase
{
    private ?ExpenseHarness $harness = null;

    protected function tearDown(): void
    {
        $this->harness?->destroy();
        $this->harness = null;
    }

    private function harness(): ExpenseHarness
    {
        return $this->harness ??= new ExpenseHarness();
    }

    public function test_recording_an_expense_takes_a_rev(): void
    {
        $h = $this->harness();

        self::assertSame(0, $h->revisions->current());

        $row = $h->expenses->record(ExpenseHarness::input());

        self::assertSame(1, (int) $row['rev'], 'a synced write with no rev never reaches a handset');
        self::assertSame(1, $h->revisions->current());
        self::assertSame(5000, (int) $row['amount']);

        $stored = $h->db->queryOne('SELECT * FROM expenses WHERE id = :id', ['id' => $row['id']]);

        self::assertNotNull($stored);
        self::assertSame('boda_fare', $stored['category']);
    }

    public function test_an_unknown_category_is_refused_and_burns_no_rev(): void
    {
        $h = $this->harness();

        try {
            $h->expenses->record(ExpenseHarness::input(category: 'bribes'));
            self::fail('an unknown category must be refused, or every report fragments on typos');
        } catch (\Throwable) {
            // The type is the module's business; that it refuses is this test's.
        }

        self::assertSame(0, $h->revisions->current(), 'a refused write must not consume a revision');
        self::assertSame(0, (int) $h->db->queryOne('SELECT COUNT(*) AS n FROM expenses')['n']);
    }

    public function test_money_is_integer_shillings_and_a_float_is_refused(): void
    {
        $h = $this->harness();

        // array_merge, not `+`: the union operator KEEPS the left-hand key, so
        // `+ ['amount' => 5000.50]` silently leaves amount at its original
        // integer and the test passes while proving nothing.
        $this->expectException(\Throwable::class);

        $h->expenses->record(array_merge(ExpenseHarness::input(), ['amount' => 5000.50]));
    }

    public function test_a_tombstoned_expense_leaves_listings_and_totals(): void
    {
        $h = $this->harness();

        $keep = $h->expenses->record(ExpenseHarness::input(amount: 5000));
        $drop = $h->expenses->record(ExpenseHarness::input(amount: 3000));

        $h->expenses->delete((int) $drop['id']);

        $stored = $h->db->queryOne('SELECT * FROM expenses WHERE id = :id', ['id' => $drop['id']]);
        self::assertNotNull($stored, 'a delete is a tombstone: the row stays so it can travel');
        self::assertNotNull($stored['deleted_at']);

        $list = $h->expenses->list(['from' => '2026-11-01', 'to' => '2026-11-30']);
        $ids  = array_map(static fn(array $r): int => (int) $r['id'], $list['data']);

        self::assertContains((int) $keep['id'], $ids);
        self::assertNotContains((int) $drop['id'], $ids, 'tombstoned rows must not list');

        $totals = $h->expenses->totalsByCategory(['from' => '2026-11-01', 'to' => '2026-11-30']);

        self::assertSame(
            5000,
            $totals['grand_total'],
            'a deleted expense must leave the TOTALS too, or every report is quietly wrong',
        );
    }

    public function test_a_category_total_over_a_range_is_exact(): void
    {
        $h = $this->harness();

        $h->expenses->record(ExpenseHarness::input(category: 'boda_fare', amount: 5000, spentOn: '2026-11-10'));
        $h->expenses->record(ExpenseHarness::input(category: 'boda_fare', amount: 2500, spentOn: '2026-11-11'));
        $h->expenses->record(ExpenseHarness::input(category: 'airtime',   amount: 10000, spentOn: '2026-11-11'));
        // Outside the window on both sides.
        $h->expenses->record(ExpenseHarness::input(category: 'boda_fare', amount: 999, spentOn: '2026-10-31'));
        $h->expenses->record(ExpenseHarness::input(category: 'boda_fare', amount: 777, spentOn: '2026-12-01'));

        $totals = $h->expenses->totalsByCategory(['from' => '2026-11-01', 'to' => '2026-11-30']);

        $byCategory = [];

        foreach ($totals['rows'] as $row) {
            $byCategory[$row['category']] = $row['total'];
        }

        self::assertSame(7500, $byCategory['boda_fare'] ?? null);
        self::assertSame(10000, $byCategory['airtime'] ?? null);
        self::assertSame(17500, $totals['grand_total']);
        self::assertIsInt($totals['grand_total'], 'money is summed as integers, never rounded floats');
    }

    public function test_totals_by_branch_separate_the_branches(): void
    {
        $h = $this->harness();

        $h->expenses->record(ExpenseHarness::input(amount: 5000, branchId: 1));
        $h->expenses->record(ExpenseHarness::input(amount: 2000, branchId: 1));
        $h->expenses->record(ExpenseHarness::input(amount: 9000, branchId: 2));

        $totals = $h->expenses->totalsByBranch(['from' => '2026-11-01', 'to' => '2026-11-30']);

        $byBranch = [];

        foreach ($totals['rows'] as $row) {
            $byBranch[(int) $row['branch_id']] = $row['total'];
        }

        self::assertSame(7000, $byBranch[1] ?? null);
        self::assertSame(9000, $byBranch[2] ?? null);
    }

    public function test_an_update_takes_a_new_rev_so_handsets_learn_of_it(): void
    {
        $h = $this->harness();

        $row = $h->expenses->record(ExpenseHarness::input());
        $before = (int) $row['rev'];

        $after = $h->expenses->update((int) $row['id'], ['amount' => 6500]);

        self::assertGreaterThan($before, (int) $after['rev']);
        self::assertSame(6500, (int) $after['amount']);
    }

    public function test_daily_totals_group_by_the_day_money_was_spent(): void
    {
        $h = $this->harness();

        $h->expenses->record(ExpenseHarness::input(amount: 1000, spentOn: '2026-11-10'));
        $h->expenses->record(ExpenseHarness::input(amount: 2000, spentOn: '2026-11-10'));
        $h->expenses->record(ExpenseHarness::input(amount: 4000, spentOn: '2026-11-12'));

        $daily = $h->expenses->dailyTotalsByBranch(['from' => '2026-11-01', 'to' => '2026-11-30']);

        $byDay = [];

        foreach ($daily['rows'] as $row) {
            $byDay[$row['spent_on']] = ($byDay[$row['spent_on']] ?? 0) + $row['total'];
        }

        self::assertSame(3000, $byDay['2026-11-10'] ?? null);
        self::assertSame(4000, $byDay['2026-11-12'] ?? null);
    }
}
