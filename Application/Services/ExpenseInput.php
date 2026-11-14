<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\LoanExpenses\Domain\ExpenseCategory;
use Plugins\LoanExpenses\Domain\PaymentMethod;

/**
 * Turns raw request input into the types the domain takes. It refuses what
 * cannot be a money or date figure rather than coercing it: a float amount
 * ("1000.5") is refused, and dates must be real YYYY-MM-DD.
 */
final class ExpenseInput
{
    /**
     * @param  array<string, mixed> $in
     * @return array{branch_id: ?int, category: ExpenseCategory, amount: int, description: ?string, payee: ?string,
     *               payment_method: PaymentMethod, reference: ?string, spent_on: \DateTimeImmutable,
     *               receipt_path: ?string, notes: ?string}
     */
    public static function record(array $in): array
    {
        return [
            'branch_id'      => self::optionalInt($in, 'branch_id'),
            'category'       => self::category($in, required: true),
            'amount'         => self::int($in, 'amount'),
            'description'    => self::optionalString($in, 'description'),
            'payee'          => self::optionalString($in, 'payee'),
            'payment_method' => self::paymentMethod($in) ?? PaymentMethod::CASH,
            'reference'      => self::optionalString($in, 'reference'),
            'spent_on'       => self::date($in, 'spent_on'),
            'receipt_path'   => self::optionalString($in, 'receipt_path'),
            'notes'          => self::optionalString($in, 'notes'),
        ];
    }

    /**
     * A partial update: only the keys present are validated and returned.
     *
     * @param  array<string, mixed> $in
     * @return array<string, mixed>  keys are the field names as the domain takes them
     */
    public static function changes(array $in): array
    {
        $out = [];

        if (array_key_exists('branch_id', $in)) {
            $out['branch_id'] = self::optionalInt($in, 'branch_id');
        }
        if (array_key_exists('category', $in)) {
            $out['category'] = self::category($in, required: true);
        }
        if (array_key_exists('amount', $in)) {
            $out['amount'] = self::int($in, 'amount');
        }
        if (array_key_exists('description', $in)) {
            $out['description'] = self::optionalString($in, 'description');
        }
        if (array_key_exists('payee', $in)) {
            $out['payee'] = self::optionalString($in, 'payee');
        }
        if (array_key_exists('payment_method', $in)) {
            $out['payment_method'] = self::paymentMethod($in) ?? PaymentMethod::CASH;
        }
        if (array_key_exists('reference', $in)) {
            $out['reference'] = self::optionalString($in, 'reference');
        }
        if (array_key_exists('spent_on', $in)) {
            $out['spent_on'] = self::date($in, 'spent_on');
        }
        if (array_key_exists('receipt_path', $in)) {
            $out['receipt_path'] = self::optionalString($in, 'receipt_path');
        }
        if (array_key_exists('notes', $in)) {
            $out['notes'] = self::optionalString($in, 'notes');
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $in
     * @return array{branch_id: ?int, category: ?string, payment_method: ?string, recorded_by: ?int, from: ?string, to: ?string, page: int, per_page: int}
     */
    public static function filters(array $in): array
    {
        $category = self::category($in, required: false);
        $method   = self::paymentMethod($in);

        return [
            'branch_id'      => self::optionalInt($in, 'branch_id'),
            'category'       => $category?->value,
            'payment_method' => $method?->value,
            'recorded_by'    => self::optionalInt($in, 'recorded_by'),
            'from'           => isset($in['from']) && $in['from'] !== '' ? self::date($in, 'from')->format('Y-m-d') : null,
            'to'             => isset($in['to']) && $in['to'] !== '' ? self::date($in, 'to')->format('Y-m-d') : null,
            'page'           => max(1, self::optionalInt($in, 'page') ?? 1),
            'per_page'       => min(100, max(1, self::optionalInt($in, 'per_page') ?? 25)),
        ];
    }

    /**
     * A required inclusive date range, refused when inverted.
     *
     * @param  array<string, mixed> $in
     * @return array{from: string, to: string, branch_id: ?int}
     */
    public static function range(array $in): array
    {
        $from = self::date($in, 'from')->format('Y-m-d');
        $to   = self::date($in, 'to')->format('Y-m-d');

        if ($from > $to) {
            throw new ServiceException('from must not be after to.', layer: 'service.expenses');
        }

        return ['from' => $from, 'to' => $to, 'branch_id' => self::optionalInt($in, 'branch_id')];
    }

    /** @param array<string, mixed> $in */
    public static function int(array $in, string $key): int
    {
        return self::optionalInt($in, $key) ?? throw self::bad($key, 'required');
    }

    /** @param array<string, mixed> $in */
    public static function optionalInt(array $in, string $key): ?int
    {
        if (!array_key_exists($key, $in) || $in[$key] === null || $in[$key] === '') {
            return null;
        }

        $value = $in[$key];

        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::bad($key, 'a whole number');
    }

    /** @param array<string, mixed> $in */
    public static function optionalString(array $in, string $key): ?string
    {
        if (!isset($in[$key])) {
            return null;
        }
        if (!is_string($in[$key])) {
            throw self::bad($key, 'text');
        }

        $text = trim($in[$key]);

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $in */
    private static function date(array $in, string $key): \DateTimeImmutable
    {
        $text = self::optionalString($in, $key) ?? throw self::bad($key, 'a date as YYYY-MM-DD');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw self::bad($key, 'a date as YYYY-MM-DD');
        }

        return $date;
    }

    /** @param array<string, mixed> $in */
    private static function category(array $in, bool $required): ?ExpenseCategory
    {
        $raw = self::optionalString($in, 'category');

        if ($raw === null) {
            return $required ? throw self::bad('category', 'one of the known expense categories') : null;
        }

        return ExpenseCategory::tryFrom($raw)
            ?? throw self::bad('category', 'one of the known expense categories');
    }

    /** @param array<string, mixed> $in */
    private static function paymentMethod(array $in): ?PaymentMethod
    {
        $raw = self::optionalString($in, 'payment_method');

        if ($raw === null) {
            return null;
        }

        return PaymentMethod::tryFrom($raw) ?? throw self::bad('payment_method', 'one of cash, mobile_money, bank_transfer, card');
    }

    private static function bad(string $field, string $expected): ServiceException
    {
        return new ServiceException(
            sprintf('%s must be %s.', $field, $expected),
            layer: 'service.expenses',
            context: ['field' => $field],
        );
    }
}
