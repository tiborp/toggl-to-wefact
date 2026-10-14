<?php
declare(strict_types=1);
namespace App\Project;

/** Parses and formats project rates as integer eurocents without float conversion. */
final class HourlyRate
{
    public static function parse(mixed $value): ?int
    {
        if (!is_string($value)) { throw new ProjectException('Voer een geldig uurtarief in, bijvoorbeeld 85,00.'); }
        $value = trim($value);
        if ($value === '') { return null; }
        // Bounded integers prevent overflow; neither parsing nor formatting uses floats.
        if (!preg_match('/^([0-9]{1,9})(?:[.,]([0-9]{1,2}))?$/D', $value, $matches)) {
            throw new ProjectException('Ongeldig uurtarief. Gebruik bijvoorbeeld 85, 85,00 of 97.50, zonder duizendtalscheiding.');
        }
        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents <= 0) { throw new ProjectException('Het uurtarief moet groter dan nul zijn.'); }
        return $cents;
    }

    public static function input(?int $cents): string
    {
        return $cents === null ? '' : intdiv($cents, 100) . ',' . sprintf('%02d', $cents % 100);
    }

    public static function format(?int $cents): string
    {
        return $cents === null ? '—' : '€ ' . self::input($cents);
    }
}
