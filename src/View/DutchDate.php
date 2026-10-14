<?php
declare(strict_types=1);
namespace App\View;

use DateTimeImmutable;
use DateTimeZone;

/** Formats visible dates in the application timezone without changing API representations. */
final class DutchDate
{
    public static function date(string $value): string
    {
        return self::parse($value)->format('d-m-Y');
    }

    public static function dateTime(string|int $value): string
    {
        return self::parse($value)->format('d-m-Y H:i');
    }

    private static function parse(string|int $value): DateTimeImmutable
    {
        $timezone = new DateTimeZone(date_default_timezone_get()); // bootstrap sets APP_TIMEZONE
        return (new DateTimeImmutable(is_int($value) ? '@' . $value : $value, $timezone))->setTimezone($timezone);
    }
}
