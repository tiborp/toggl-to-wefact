<?php
declare(strict_types=1);
namespace App\Invoice;

use PDO;

/** Reads immutable local invoice history; never consults live billing configuration or APIs. */
final class Specification
{
    public function __construct(private readonly PDO $pdo) {}

    /** Returns null for an unknown local invoice, or an unavailable specification if audit data is incomplete. */
    public function find(int $id): ?array
    {
        $query = $this->pdo->prepare('SELECT i.*, a.snapshot_json FROM invoices i LEFT JOIN invoice_actions a ON a.token_hash = i.token_hash WHERE i.id = ?');
        $query->execute([$id]);
        $invoice = $query->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { return null; }
        // Released entries still belong to this historical invoice and must remain visible here.
        $query = $this->pdo->prepare('SELECT * FROM invoiced_entries WHERE invoice_id = ? ORDER BY start, id');
        $query->execute([$id]);
        return self::build($invoice, $query->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Verify completeness against the original selection, then group exact seconds without financial allocation.
     * Remote status and local lifecycle deliberately do not restrict access to historical specifications.
     */
    public static function build(array $invoice, array $entries): array
    {
        $result = ['invoice' => $invoice, 'available' => false, 'groups' => [], 'actual_seconds' => null];
        $snapshot = json_decode($invoice['snapshot_json'] ?? '', true);
        $expected = $snapshot['entry_ids'] ?? null;
        if (!is_array($expected) || $expected === [] || !is_int($invoice['duration_seconds'] ?? null)
            || !is_int($invoice['billed_duration_seconds'] ?? null) || $invoice['billed_duration_seconds'] < 0) {
            return $result;
        }
        $ids = []; $groups = []; $total = 0;
        foreach ($entries as $entry) {
            $seconds = $entry['duration_seconds'] ?? null;
            if (!is_int($seconds) || $seconds < 0 || $seconds > PHP_INT_MAX - $total
                || !is_string($entry['description'] ?? null)
                || (string)($entry['toggl_project_id'] ?? '') !== (string)$invoice['toggl_project_id']) { return $result; }
            $id = (string)($entry['toggl_time_entry_id'] ?? '');
            if (!preg_match('/^[1-9][0-9]*$/D', $id) || isset($ids[$id])) { return $result; }
            $ids[$id] = true;
            $description = trim($entry['description']);
            $key = mb_strtolower($description, 'UTF-8');
            $groups[$key] ??= ['description' => $description === '' ? 'Werkzaamheden' : $description, 'duration_seconds' => 0, 'entries' => []];
            $groups[$key]['duration_seconds'] += $seconds;
            $groups[$key]['entries'][] = $entry;
            $total += $seconds;
        }
        foreach ($expected as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string)$id)) { return $result; }
        }
        $expected = array_map('strval', $expected); $found = array_map('strval', array_keys($ids));
        sort($expected); sort($found);
        if ($found !== $expected || $total !== $invoice['duration_seconds']) { return $result; }
        ksort($groups, SORT_STRING);
        return ['invoice' => $invoice, 'available' => true, 'groups' => array_values($groups), 'actual_seconds' => $total];
    }
}
