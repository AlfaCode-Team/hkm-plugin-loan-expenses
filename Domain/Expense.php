<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Domain;

/**
 * One recorded expense. Enforces what an expense MEANS: a positive whole-UGX
 * amount, a known category, a known payment method. Storage and transport
 * concerns stay out of here.
 */
final readonly class Expense
{
    private function __construct(
        public ?int $branchId,
        public ExpenseCategory $category,
        public int $amount,
        public ?string $description,
        public ?string $payee,
        public PaymentMethod $paymentMethod,
        public ?string $reference,
        public \DateTimeImmutable $spentOn,
        public ?string $receiptPath,
        public ?string $notes,
    ) {
    }

    /** @throws \DomainException */
    public static function of(
        ?int $branchId,
        ExpenseCategory $category,
        int $amount,
        ?string $description,
        ?string $payee,
        PaymentMethod $paymentMethod,
        ?string $reference,
        \DateTimeImmutable $spentOn,
        ?string $receiptPath,
        ?string $notes,
    ): self {
        if ($amount <= 0) {
            throw new \DomainException('An expense must be more than zero shillings.');
        }

        return new self(
            $branchId,
            $category,
            $amount,
            $description,
            $payee,
            $paymentMethod,
            $reference,
            $spentOn,
            $receiptPath,
            $notes,
        );
    }
}
