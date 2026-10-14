<?php
declare(strict_types=1);
namespace App\Toggl;

/** Centralizes billing eligibility from full invoice tags, local registration and reservations. */
final class TimeEntryStatus
{
    private const INVOICED_TAGS = [
        'invoiced' => 'WeFact',
        'billed by factuursturen.nl' => 'Factuursturen.nl',
    ];

    /**
     * Recognize full tag names without substring matches; local success is an independent source.
     * @return list<string> Deduplicated display labels for recognized invoice sources.
     */
    public static function invoiceSources(array $entry): array
    {
        $sources = [];
        foreach ($entry['tags'] as $tag) {
            $source = self::INVOICED_TAGS[strtolower($tag)] ?? null;
            if ($source !== null) { $sources[$source] = $source; }
        }
        if (!empty($entry['locally_invoiced'])) { $sources['WeFact'] = 'WeFact'; }
        return array_values($sources);
    }

    public static function isInvoiced(array $entry): bool
    {
        return self::invoiceSources($entry) !== [];
    }

    public static function isRunning(array $entry): bool
    {
        return $entry['duration'] < 0 || $entry['stop'] === null;
    }

    /**
     * Check entry-level eligibility only; project readiness is validated separately by PreviewService.
     */
    public static function isBillable(array $entry): bool
    {
        return !self::isRunning($entry) && !self::isInvoiced($entry) && empty($entry['billing_blocked']);
    }
}
