<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Domain;

/** How an expense was paid. Closed for the same reason as ExpenseCategory. */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case MOBILE_MONEY = 'mobile_money';
    case BANK_TRANSFER = 'bank_transfer';
    case CARD = 'card';
}
