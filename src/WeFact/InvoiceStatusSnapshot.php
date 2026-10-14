<?php
declare(strict_types=1);
namespace App\WeFact;

use App\Invoice\ApiDecimal;
use DateTimeImmutable;

/** Normalizes a remote observation without inferring payment or delivery from unrelated fields. */
final class InvoiceStatusSnapshot
{
    public static function label(?int $code): string
    {
        return match ($code) {
            0 => 'Concept', 2 => 'Verzonden', 3 => 'Deels betaald', 4 => 'Betaald',
            8 => 'Creditfactuur', 9 => 'Vervallen', default => 'Onbekend',
        };
    }

    /** Required identity/status must be valid; absent optional values remain unknown, never zero. */
    public static function parse(array $row, string $identifier): array
    {
        if (self::integer($row['Identifier'] ?? null) !== self::integer($identifier)
            || self::integer($row['Status'] ?? null) === null
            || !is_string($row['InvoiceCode'] ?? null) || trim($row['InvoiceCode']) === '') {
            throw new \UnexpectedValueException();
        }
        $result = ['status_code'=>self::integer($row['Status']), 'invoice_code'=>$row['InvoiceCode'],
            'sent_count'=>self::integer($row['Sent'] ?? null)];
        foreach (['SubStatus'=>'sub_status', 'Currency'=>'currency'] as $field=>$column) {
            $value = $row[$field] ?? null;
            if ($value !== null && !is_string($value)) { throw new \UnexpectedValueException(); }
            if ($field === 'Currency' && $value !== null && $value !== '' && !preg_match('/^[A-Z]{3}$/D', $value)) {
                throw new \UnexpectedValueException();
            }
            $result[$column] = $value === '' ? null : $value;
        }
        foreach (['Date'=>'invoice_date', 'PayDate'=>'paid_on', 'PayBefore'=>'pay_before', 'SentDate'=>'sent_at'] as $field=>$column) {
            $result[$column] = self::date($row[$field] ?? null, $field === 'SentDate');
        }
        foreach (['AmountIncl'=>'amount_incl_cents', 'AmountPaid'=>'amount_paid_cents', 'AmountOutstanding'=>'amount_outstanding_cents'] as $field=>$column) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '') { $result[$column] = null; continue; }
            if (!is_string($value)) { throw new \UnexpectedValueException(); }
            // Credit balances can be negative. Reuse the exact cent parser on the magnitude.
            $negative = str_starts_with($value, '-');
            $cents = ApiDecimal::cents($negative ? substr($value, 1) : $value);
            $result[$column] = $negative ? -$cents : $cents;
        }
        return $result;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) { return $value; }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value)) { return (int)$value; }
        return null;
    }

    private static function date(mixed $value, bool $time): ?string
    {
        if (!is_string($value)) { return null; }
        $pattern = $time ? '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})?$/D' : '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D';
        if (!preg_match($pattern, $value) || substr($value, 0, 4) === '0000') { return null; }
        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0) ? $value : null;
        } catch (\Throwable) { return null; }
    }
}
