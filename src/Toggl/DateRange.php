<?php
declare(strict_types=1);
namespace App\Toggl;

use DateTimeImmutable;
use DateTimeZone;

/** Parses inclusive local dates and exposes an exclusive upper bound for API requests. */
final class DateRange
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $endExclusive,
    ) {}

    /** Calendar subtraction with month-end clamping (31 May → 28/29 February). */
    public static function earliestStart(DateTimeZone $timezone, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
        $month = $today->modify('first day of this month')->modify('-3 months');
        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $today->format('j'), (int) $month->format('t')));
    }

    public function assertAvailableFrom(DateTimeImmutable $earliest): void
    {
        if ($this->from < $earliest->format('Y-m-d')) {
            throw new TogglException('Je kunt maximaal drie kalendermaanden terug uren ophalen. Kies een van-datum op of na ' . $earliest->format('d-m-Y') . '.');
        }
    }

    public static function fromInput(mixed $from, mixed $to, DateTimeZone $timezone): self
    {
        $parse = static function (mixed $value) use ($timezone): DateTimeImmutable {
            if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || substr($value, 0, 4) === '0000') {
                throw new TogglException('Vul beide datums in als geldige datum (jaar-maand-dag).');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new TogglException('Vul beide datums in als geldige datum (jaar-maand-dag).');
            }
            return $date;
        };
        $start = $parse($from);
        $lastDay = $parse($to);
        if ($start > $lastDay) { throw new TogglException('De van-datum mag niet na de tot-datum liggen.'); }
        return new self($from, $to, $start, $lastDay->modify('+1 day'));
    }
}
