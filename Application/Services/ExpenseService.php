<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;
use Plugins\LoanExpenses\API\Contracts\ExpenseServiceContract;
use Plugins\LoanExpenses\Domain\Expense;
use Plugins\LoanExpenses\Domain\ExpenseCategory;
use Plugins\LoanExpenses\Domain\PaymentMethod;
use Plugins\LoanExpenses\Infrastructure\Persistence\ExpenseRepository;
use Plugins\LoanExpenses\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * The expenses workflow: record, change, tombstone, and report.
 *
 * Every write follows one shape: begin, reserve the revision INSIDE the
 * transaction, mint the id, save under that revision, commit. A row written
 * without a rev never reaches a handset, so this is not optional.
 */
final class ExpenseService implements ExpenseServiceContract
{
    private const string DEVICE = 'server';

    public function __construct(
        private readonly ExpenseRepository $expenses,
        private readonly LegacyIdAllocator $ids,
        private readonly SyncRevisionContract $revisions,
        private readonly TransactionManager $transaction,
        private readonly ClockPort $clock,
        private readonly Identity $identity,
        private readonly StaffDirectoryContract $staff,
    ) {
    }

    public function list(array $filters): array
    {
        $f = ExpenseInput::filters($filters);

        $result = $this->expenses->search(
            [
                'branch_id'      => $f['branch_id'],
                'category'       => $f['category'],
                'payment_method' => $f['payment_method'],
                'recorded_by'    => $f['recorded_by'],
                'from'           => $f['from'],
                'to'             => $f['to'],
            ],
            $f['per_page'],
            ($f['page'] - 1) * $f['per_page'],
        );

        return [
            'data'     => $result['rows'],
            'page'     => $f['page'],
            'per_page' => $f['per_page'],
            'total'    => $result['total'],
        ];
    }

    public function show(int $id): array
    {
        return $this->requireLive($id);
    }

    public function record(array $input): array
    {
        $in  = ExpenseInput::record($input);
        $now = $this->clock->now();

        $actor = $this->actorId();
        if ($actor === null) {
            throw new ServiceException('An expense must be recorded by a signed-in person.', layer: 'service.expenses');
        }

        $expense = $this->domain(static fn(): Expense => Expense::of(
            branchId: $in['branch_id'],
            category: $in['category'],
            amount: $in['amount'],
            description: $in['description'],
            payee: $in['payee'],
            paymentMethod: $in['payment_method'],
            reference: $in['reference'],
            spentOn: $in['spent_on'],
            receiptPath: $in['receipt_path'],
            notes: $in['notes'],
        ));

        $this->transaction->begin();

        try {
            $rev = $this->revisions->next();
            $id  = $this->ids->reserve('expenses');

            $this->expenses->insert(
                $this->toRow($id, $expense, $actor, $this->identity->fullName ?: $this->identity->username ?: null),
                $rev,
                self::DEVICE,
                $this->stamp($now),
            );

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $this->wrap($e, 'The expense could not be recorded.');
        }

        return $this->show($id);
    }

    public function update(int $id, array $changes): array
    {
        if ($changes === []) {
            return $this->show($id);
        }

        $patch = ExpenseInput::changes($changes);
        $now   = $this->clock->now();

        $this->transaction->begin();

        try {
            $current = $this->expenses->find($id) ?? throw $this->notFound($id);

            // Validate the whole record as it will read after the change, so an
            // edit cannot leave a row the domain would have refused on creation.
            $merged = $this->merge($current, $patch);
            $expense = Expense::of(
                branchId: $merged['branch_id'],
                category: $merged['category'],
                amount: $merged['amount'],
                description: $merged['description'],
                payee: $merged['payee'],
                paymentMethod: $merged['payment_method'],
                reference: $merged['reference'],
                spentOn: $merged['spent_on'],
                receiptPath: $merged['receipt_path'],
                notes: $merged['notes'],
            );

            $rev = $this->revisions->next();

            $row = $this->toRow($id, $expense, (int) $current['recorded_by'], $current['recorded_by_name'] ?? null);
            unset($row['id']);

            $this->expenses->update($id, $row, $rev, self::DEVICE, $this->stamp($now));

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $this->wrap($e, 'The expense could not be changed.');
        }

        return $this->show($id);
    }

    public function delete(int $id): void
    {
        $now = $this->clock->now();

        $this->transaction->begin();

        try {
            $this->requireLive($id);

            $rev = $this->revisions->next();

            $this->expenses->tombstone($id, $rev, self::DEVICE, $this->stamp($now));

            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw $this->wrap($e, 'The expense could not be deleted.');
        }
    }

    public function totalsByCategory(array $filters): array
    {
        $r    = ExpenseInput::range($filters);
        $rows = $this->expenses->totalsByCategory(['from' => $r['from'], 'to' => $r['to'], 'branch_id' => $r['branch_id']]);

        $grand = 0;
        $out   = [];
        foreach ($rows as $row) {
            $grand += $row['total'];
            $out[] = [
                'category' => $row['category'],
                'group'    => ExpenseCategory::tryFrom($row['category'])?->group(),
                'total'    => $row['total'],
                'count'    => $row['count'],
            ];
        }

        return ['from' => $r['from'], 'to' => $r['to'], 'grand_total' => $grand, 'rows' => $out];
    }

    public function totalsByBranch(array $filters): array
    {
        $r    = ExpenseInput::range($filters);
        $rows = $this->expenses->totalsByBranch(['from' => $r['from'], 'to' => $r['to']]);

        $grand = 0;
        foreach ($rows as $row) {
            $grand += $row['total'];
        }

        return ['from' => $r['from'], 'to' => $r['to'], 'grand_total' => $grand, 'rows' => $rows];
    }

    public function dailyTotalsByBranch(array $filters): array
    {
        $r = ExpenseInput::range($filters);

        return [
            'from' => $r['from'],
            'to'   => $r['to'],
            'rows' => $this->expenses->dailyTotalsByBranch($r),
        ];
    }

    /** @return array<string, mixed> */
    private function requireLive(int $id): array
    {
        return $this->expenses->find($id) ?? throw $this->notFound($id);
    }

    /**
     * @param  array<string, mixed> $current
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function merge(array $current, array $patch): array
    {
        $existing = [
            'branch_id'      => $current['branch_id'] === null ? null : (int) $current['branch_id'],
            'category'       => ExpenseCategory::tryFrom((string) $current['category'])
                ?? throw new ServiceException('The stored category is no longer known; correct it before editing.', layer: 'service.expenses'),
            'amount'         => (int) $current['amount'],
            'description'    => $current['description'],
            'payee'          => $current['payee'],
            'payment_method' => PaymentMethod::tryFrom((string) $current['payment_method']) ?? PaymentMethod::CASH,
            'reference'      => $current['reference'],
            'spent_on'       => new \DateTimeImmutable((string) $current['spent_on']),
            'receipt_path'   => $current['receipt_path'],
            'notes'          => $current['notes'],
        ];

        return $patch + $existing;
    }

    /** @return array<string, mixed> */
    private function toRow(int $id, Expense $e, int $recordedBy, ?string $recordedByName): array
    {
        return [
            'id'             => $id,
            'branch_id'      => $e->branchId,
            'category'       => $e->category->value,
            'amount'         => $e->amount,
            'description'    => $e->description,
            'payee'          => $e->payee,
            'payment_method' => $e->paymentMethod->value,
            'reference'      => $e->reference,
            'spent_on'       => $e->spentOn->format('Y-m-d'),
            'receipt_path'   => $e->receiptPath,
            'recorded_by'    => $recordedBy,
            'recorded_by_name' => $recordedByName,
            'notes'          => $e->notes,
        ];
    }

    /**
     * @template T
     * @param  callable(): T $build
     * @return T
     */
    private function domain(callable $build): mixed
    {
        try {
            return $build();
        } catch (\DomainException $e) {
            throw new ServiceException($e->getMessage(), layer: 'service.expenses', previous: $e);
        }
    }

    private function notFound(int $id): ServiceException
    {
        return new ServiceException(sprintf('Expense [%d] was not found.', $id), layer: 'service.expenses', context: ['id' => $id]);
    }

    private function wrap(\Throwable $e, string $fallback): ServiceException
    {
        if ($e instanceof ServiceException) {
            return $e;
        }
        if ($e instanceof \DomainException) {
            return new ServiceException($e->getMessage(), layer: 'service.expenses', previous: $e);
        }

        return new ServiceException($fallback, layer: 'service.expenses', previous: $e);
    }

    private function stamp(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d H:i:s');
    }

    private function actorId(): ?int
    {
        if ($this->identity->isGuest()) {
            return null;
        }

        // `recorded_by` is a staff_members.id, not the users.id on the token.
        // Both are numeric, so writing the wrong one fails nothing and books
        // the spending against a different person entirely.
        return $this->staff->staffIdForUser($this->identity->userId);
    }
}
