<?php
declare(strict_types=1);
namespace App\Invoice;

use PDO;

/** Stores observations separately; never changes invoice registrations, reservations or entry state. */
final class RemoteStatusRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function candidates(): array
    {
        return $this->pdo->query("SELECT i.id,i.wefact_invoice_id,i.wefact_source_fingerprint FROM invoices i JOIN invoice_actions a ON a.token_hash=i.token_hash WHERE i.lifecycle='active' AND a.state='succeeded' ORDER BY i.id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One observation is atomic; no database lock is held while calling WeFact. */
    public function success(int $id, array $snapshot, string $at): void
    {
        $this->pdo->beginTransaction();
        try {
            $values = ['invoice_id'=>$id] + $snapshot + ['checked_at'=>$at, 'last_attempt_at'=>$at, 'last_attempt_result'=>'success', 'last_error_kind'=>null];
            $columns = array_keys($values);
            $updates = array_map(static fn($c)=>$c.'=excluded.'.$c, array_slice($columns, 1));
            $q = $this->pdo->prepare('INSERT INTO invoice_remote_status ('.implode(',', $columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).') ON CONFLICT(invoice_id) DO UPDATE SET '.implode(',', $updates));
            $q->execute(array_values($values));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack(); throw $e;
        }
    }

    /** A failed attempt updates metadata only, preserving every field of the last valid snapshot. */
    public function failure(int $id, string $kind, string $at): void
    {
        $q = $this->pdo->prepare("INSERT INTO invoice_remote_status(invoice_id,last_attempt_at,last_attempt_result,last_error_kind) VALUES(?,?,'failed',?) ON CONFLICT(invoice_id) DO UPDATE SET last_attempt_at=excluded.last_attempt_at,last_attempt_result='failed',last_error_kind=excluded.last_error_kind");
        $q->execute([$id, $at, $kind]);
    }
}
