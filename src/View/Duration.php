<?php
declare(strict_types=1);
namespace App\View;

/** Formats seconds for display only; omitted seconds remain part of financial calculations. */
final class Duration
{
    public static function exact(int $seconds): string
    {
        return self::hoursMinutes($seconds).sprintf(':%02d',$seconds%60);
    }

    public static function hoursMinutes(int $seconds): string
    {
        if ($seconds < 0) { throw new \InvalidArgumentException('Actieve/negatieve durations hebben geen vaste tijdsweergave.'); }
        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
