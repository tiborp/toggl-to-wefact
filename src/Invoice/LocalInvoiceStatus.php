<?php
declare(strict_types=1);
namespace App\Invoice;

use PDO;
/** Adds authoritative local invoice and reservation status to fetched Toggl entries. */
final class LocalInvoiceStatus
{
    public function __construct(private readonly PDO $pdo) {}
    /**
     * Add locally_invoiced and billing_blocked flags without changing durations or tags.
     * Local success remains authoritative even if the secondary Toggl tag update failed.
     */
    public function annotate(array $entries): array
    {
        $invoiced=array_flip($this->pdo->query('SELECT toggl_time_entry_id FROM invoiced_entries WHERE released_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        $blocked=array_flip($this->pdo->query('SELECT toggl_time_entry_id FROM invoice_entry_reservations')->fetchAll(PDO::FETCH_COLUMN));
        foreach($entries as &$e){$e['locally_invoiced']=isset($invoiced[$e['id']]);$e['billing_blocked']=isset($blocked[$e['id']])&&!$e['locally_invoiced'];}unset($e);
        return $entries;
    }
}
