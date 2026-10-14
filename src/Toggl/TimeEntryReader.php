<?php
declare(strict_types=1);
namespace App\Toggl;

use DateTimeImmutable;
use DateTimeZone;

/** Fetches a bounded period with quota-aware splitting and explicit completeness metadata. */
final class TimeEntryReader
{
    public const MAX_REQUESTS = 8;
    public const RESPONSE_LIMIT = 1000;

    public function __construct(private readonly TogglClient $client) {}

    /**
     * Preserve available results when a later subrange fails; callers must inspect complete.
     * An empty batch is valid. Filtering entries later must never redefine fetch completeness.
     * requests counts successfully parsed client calls, not failed HTTP attempts.
     *
     * @return array{entries: list<array>, complete: bool, warning: ?string, requests: int}
     */
    public function read(DateRange $range): array
    {
        $pending = [[$range->start->getTimestamp(), $range->endExclusive->getTimestamp()]];
        $entries = [];
        $requests = 0;
        $complete = true;
        $warning = null;
        while ($pending !== []) {
            if ($requests >= self::MAX_REQUESTS) {
                $complete = false;
                $warning = 'Resultaten onvolledig: de limiet van acht bulk-opvragen per actie is bereikt. Kies een kleinere periode.';
                break;
            }
            [$start, $end] = array_pop($pending);
            try {
                $batch = $this->client->timeEntries($this->instant($start), $this->instant($end));
                $requests++;
            } catch (TogglException $error) {
                $complete = false;
                $warning = 'Resultaten onvolledig: ' . $error->getMessage();
                break;
            }
            // Preserve available parent results if a later subrange cannot be fetched.
            // ID deduplication prevents overlap/boundary entries from inflating totals.
            foreach ($batch as $entry) {
                $timestamp = (new DateTimeImmutable($entry['start']))->getTimestamp();
                if ($timestamp >= $range->start->getTimestamp() && $timestamp < $range->endExclusive->getTimestamp()) {
                    $entries[$entry['id']] = $entry;
                }
            }
            if (count($batch) >= self::RESPONSE_LIMIT) {
                if ($end - $start < 2) {
                    $complete = false;
                    $warning = 'Resultaten onvolledig: minstens 1.000 entries binnen één seconde. Deze API kan de volledige lijst niet leveren.';
                    continue;
                }
                $middle = $start + intdiv($end - $start, 2);
                $pending[] = [$middle, $end];
                $pending[] = [$start, $middle];
            }
        }
        return ['entries' => array_values($entries), 'complete' => $complete, 'warning' => $warning, 'requests' => $requests];
    }

    private function instant(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
