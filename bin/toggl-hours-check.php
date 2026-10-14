<?php
declare(strict_types=1);

use App\Config\Config;
use App\Toggl\{DateRange, CurlTransport, Quota, TogglClient, TogglException, TimeEntryStatus};
require dirname(__DIR__) . '/vendor/autoload.php';
$config = new Config(dirname(__DIR__));
$timezone = new DateTimeZone($config->get('APP_TIMEZONE', 'Europe/Amsterdam'));
if ($config->togglToken() === '') {
    echo "Geen Toggl-token in .env; API niet benaderd.\n";
    exit(0);
}
try {
    // Explicit dates, one bulk request only, no project or user requests.
    $range = DateRange::fromInput($argv[1] ?? null, $argv[2] ?? null, $timezone);
    $entries = (new TogglClient($config->togglToken(), new CurlTransport(), new Quota()))->timeEntries($range->start, $range->endExclusive);
    $entries = array_filter($entries, static function ($entry) use ($range): bool {
        $start = new DateTimeImmutable($entry['start']);
        return $start >= $range->start && $start < $range->endExclusive;
    });
    $historical = count(array_filter($entries, static fn ($entry) => in_array('Factuursturen.nl', TimeEntryStatus::invoiceSources($entry), true)));
    $invoiced = count(array_filter($entries, static fn ($entry) => TimeEntryStatus::isInvoiced($entry)));
    $running = count(array_filter($entries, static fn ($entry) => TimeEntryStatus::isRunning($entry)));
    echo 'Entries in de periode: ' . count($entries) . "\n";
    echo 'Gefactureerd: ' . $invoiced . "\n";
    echo 'Factuursturen.nl-tag herkend: ' . $historical . "\n";
    echo 'Running: ' . $running . "\n";
    echo "Read-only controle voltooid met één GET-request; geen data gewijzigd of opgeslagen.\n";
} catch (TogglException $error) {
    echo $error->getMessage() . "\n";
    exit(1);
}
