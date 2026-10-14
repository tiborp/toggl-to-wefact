<?php
declare(strict_types=1);
namespace App\Invoice;

use PDO;
use Throwable;

/** Persists invoice snapshots, entry claims and lifecycle transitions for duplicate prevention. */
class InvoiceRepository
{
    public function __construct(protected readonly PDO $pdo) {}
    public static function modelHash(array $model): string { return hash('sha256',json_encode($model,JSON_THROW_ON_ERROR)); }
    /**
     * Store immutable confirmation input and a hash of the token; no entry reservation yet.
     */
    public function prepare(string $token,string $owner,string $dataset,string $source,array $model,array $payload = [], string $revision = ''): void
    {
        $hash=self::modelHash($model);$now=gmdate('c');
        $query=$this->pdo->prepare('INSERT INTO invoice_actions(token_hash,action_fingerprint,owner_hash,dataset_id,toggl_project_id,source_fingerprint,preview_hash,snapshot_json,payload_json,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $query->execute([hash('sha256',$token),hash('sha256',$owner.$dataset.$revision.$model['toggl_project_id'].$hash),$owner,$dataset,$model['toggl_project_id'],$source,$hash,json_encode($model,JSON_THROW_ON_ERROR),json_encode($payload,JSON_THROW_ON_ERROR),'prepared',$now,$now]);
    }
    public function action(string $token,string $owner): array
    {
        $q=$this->pdo->prepare('SELECT * FROM invoice_actions WHERE token_hash=? AND owner_hash=?');$q->execute([hash('sha256',$token),$owner]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){throw new InvoiceException('Bevestiging ontbreekt of hoort niet bij deze sessie.');}
        return $row;
    }
    /**
     * Commit unique entry reservations and an uncertainty marker before the remote write.
     * The marker deliberately survives a crash: absence of local success is not proof of rejection.
     * @param \Closure|null $validate Rechecks local configuration within the claim transaction.
     * @throws InvoiceException If entries are already claimed/invoiced or the guard cannot be persisted.
     */
    public function claim(string $token,string $owner,array $model,array $payload, ?\Closure $validate = null): void
    {
        $started=false;
        try{
            $this->pdo->beginTransaction();$started=true;
            if($validate!==null){$validate();}
            $action=$this->action($token,$owner);
            if($action['state']!=='prepared'){throw new InvoiceException('Deze bevestiging is al gebruikt of onzeker. Controleer de lokale factuurhistorie; er wordt geen tweede API-call gedaan.');}
            $check=$this->pdo->prepare('SELECT 1 FROM invoiced_entries WHERE toggl_time_entry_id=? AND released_at IS NULL');
            $reserve=$this->pdo->prepare('INSERT INTO invoice_entry_reservations(toggl_time_entry_id,token_hash) VALUES(?,?)');
            foreach($model['entry_ids'] as $id){$check->execute([$id]);if($check->fetchColumn()){throw new InvoiceException('Een geselecteerde entry is al lokaal gefactureerd.');}$reserve->execute([$id,$action['token_hash']]);}
            // Durable uncertainty marker BEFORE the external write; a crash must never enable a retry.
            $update=$this->pdo->prepare("UPDATE invoice_actions SET state='reconciliation_required',payload_json=?,message=?,updated_at=? WHERE token_hash=? AND state='prepared'");
            $update->execute([json_encode($payload,JSON_THROW_ON_ERROR),'Write gestart; uitkomst moet worden gecontroleerd.',gmdate('c'),$action['token_hash']]);
            if($update->rowCount()!==1){throw new InvoiceException('Bevestiging is gelijktijdig verwerkt.');}
            $this->pdo->commit();
        }catch(Throwable $e){if($started&&$this->pdo->inTransaction()){$this->pdo->rollBack();}if($e instanceof InvoiceException){throw $e;}throw new InvoiceException('Entries zijn gereserveerd/gefactureerd of de lokale beveiliging kon niet worden opgeslagen. Er is geen API-write uitgevoerd.');}
    }
    public function complete(string $token,string $owner,array $model,array $remote,string $source,string $date): int
    {
        return $this->completeHash(hash('sha256',$token),$owner,$model,$remote,$source,$date);
    }
    /**
     * Reconcile remote identity, invoice snapshot and entries in one local transaction.
     * Joins a caller-owned transaction during manual reconciliation; otherwise owns its transaction.
     * @return int Newly registered local invoice ID.
     * @throws InvoiceException Registration failed; a remote concept may already exist.
     */
    public function completeHash(string $hash,string $owner,array $model,array $remote,string $source,string $date): int
    {
        $started=false;
        try{
            if(!$this->pdo->inTransaction()){$this->pdo->beginTransaction();$started=true;}
            $q=$this->pdo->prepare('SELECT * FROM invoice_actions WHERE token_hash=? AND owner_hash=?');$q->execute([$hash,$owner]);$action=$q->fetch(PDO::FETCH_ASSOC);
            if(!$action||$action['state']!=='reconciliation_required'){throw new InvoiceException('Onverwachte lokale factuuractie.');}
            $q=$this->pdo->prepare('INSERT INTO invoices(token_hash,toggl_project_id,project_name,wefact_debtor_id,debtor_name,wefact_source_fingerprint,wefact_invoice_id,wefact_invoice_code,wefact_status,invoice_date,period_from,period_to,hourly_rate_cents,duration_seconds,billed_duration_seconds,amount_excl_cents,line_mode,lines_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $now=gmdate('c');
            $q->execute([$action['token_hash'],$model['toggl_project_id'],$model['project_name'],$model['wefact_debtor_id'],$model['wefact_display_name'],$source,$remote['id'],$remote['code'],0,$date,$model['from'],$model['to'],$model['hourly_rate_cents'],$model['duration_seconds'],$model['billed_duration_seconds'],$model['total_cents'],$model['mode'],json_encode($model['lines'],JSON_THROW_ON_ERROR),$now]);
            $id=(int)$this->pdo->lastInsertId();
            $entry=$this->pdo->prepare('INSERT INTO invoiced_entries(invoice_id,toggl_time_entry_id,toggl_project_id,duration_seconds,description,start,stop,workspace_id,tags_json,wefact_invoice_id,wefact_invoice_number,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($model['entries'] as $e){$entry->execute([$id,$e['id'],$e['project_id'],$e['duration'],$e['description'],$e['start'],$e['stop'],$e['workspace_id'],json_encode($e['tags'],JSON_THROW_ON_ERROR),$remote['id'],$remote['code'],$now]);}
            $q=$this->pdo->prepare("UPDATE invoice_actions SET state='succeeded',wefact_invoice_id=?,wefact_invoice_code=?,message=NULL,updated_at=? WHERE token_hash=?");$q->execute([$remote['id'],$remote['code'],$now,$action['token_hash']]);
            if($started){$this->pdo->commit();}return $id;
        }catch(Throwable){if($started&&$this->pdo->inTransaction()){$this->pdo->rollBack();}throw new InvoiceException('WeFact kan de conceptfactuur al hebben aangemaakt, maar SQLite kon die niet betrouwbaar registreren. Niet opnieuw proberen: handmatige controle in WeFact en lokale historie is nodig.');}
    }
    public function uncertain(string $token,string $message,?array $remote=null): void
    {
        try{$q=$this->pdo->prepare("UPDATE invoice_actions SET message=?,wefact_invoice_id=?,wefact_invoice_code=?,updated_at=? WHERE token_hash=? AND state='reconciliation_required'");$q->execute([$message,$remote['id']??null,$remote['code']??null,gmdate('c'),hash('sha256',$token)]);}catch(Throwable){/* pre-write marker and reservations remain durable */}
    }
    public function result(string $token,string $outcome,array $diagnostics): void
    {
        try{$this->writeResult(hash('sha256',$token),$outcome,$diagnostics);}catch(Throwable){/* durable guard/registration remains authoritative */}
    }
    private function writeResult(string $hash,string $outcome,array $diagnostics): void
    {
        $q=$this->pdo->prepare('INSERT INTO invoice_action_results(token_hash,outcome,diagnostics_json,captured_at) VALUES(?,?,?,?) ON CONFLICT(token_hash) DO UPDATE SET outcome=excluded.outcome,diagnostics_json=excluded.diagnostics_json,captured_at=excluded.captured_at');
        $q->execute([$hash,$outcome,json_encode($diagnostics,JSON_THROW_ON_ERROR),gmdate('c')]);
    }
    /**
     * Cancel a definitely rejected write and release only its reservations in one transaction.
     * If local cleanup fails, retain the guard instead of risking duplicate invoicing.
     */
    public function rejected(string $token,string $owner,string $message,array $diagnostics): void
    {
        $started=false;
        try{
            $this->pdo->beginTransaction();$started=true;$action=$this->action($token,$owner);
            if($action['state']!=='reconciliation_required'){throw new InvoiceException('Afwijzing hoort niet bij een gestarte factuuractie.');}
            $this->writeResult($action['token_hash'],'rejected',$diagnostics);
            $q=$this->pdo->prepare("UPDATE invoice_actions SET state='cancelled',message=?,updated_at=? WHERE token_hash=?");
            $q->execute([$message,gmdate('c'),$action['token_hash']]);
            $q=$this->pdo->prepare('DELETE FROM invoice_entry_reservations WHERE token_hash=?');$q->execute([$action['token_hash']]);
            $this->pdo->commit();
        }catch(Throwable){
            if($started&&$this->pdo->inTransaction()){$this->pdo->rollBack();}
            throw new InvoiceException('WeFact wees de actie af, maar de lokale afhandeling mislukte. Reserveringen blijven veilig geblokkeerd; handmatige controle nodig.');
        }
    }
    /**
     * Retain claims and remote identity when creation is known but registration needs review.
     * A cancelled action here does not mean no invoice exists: created_review remains open.
     */
    public function createdReview(string $token,string $message,array $remote,array $diagnostics): void
    {
        $started=false;
        try{
            $this->pdo->beginTransaction();$started=true;
            $hash=hash('sha256',$token);$this->writeResult($hash,'created_review',$diagnostics);
            $q=$this->pdo->prepare("UPDATE invoice_actions SET state='cancelled',message=?,wefact_invoice_id=?,wefact_invoice_code=?,updated_at=? WHERE token_hash=? AND state='reconciliation_required'");
            $q->execute([$message,$remote['id'],$remote['code'],gmdate('c'),$hash]);
            $this->pdo->commit();
            // KEEP entry reservations: an invoice exists, but registration/status/amount needs review.
        }catch(Throwable){
            if($started&&$this->pdo->inTransaction()){$this->pdo->rollBack();}
            $this->uncertain($token,$message,$remote);
        }
    }

    public function cancel(string $token,string $owner): void
    {
        $q=$this->pdo->prepare("UPDATE invoice_actions SET state='cancelled',updated_at=? WHERE token_hash=? AND owner_hash=? AND state='prepared'");$q->execute([gmdate('c'),hash('sha256',$token),$owner]);
    }
    // Read stored billed duration for audit display without recalculating the snapshot.
    public static function snapshotDuration(array $snapshot): ?int
    {
        if (isset($snapshot['billed_duration_seconds'])) { return $snapshot['billed_duration_seconds']; }
        if (isset($snapshot['duration_seconds'])) { return $snapshot['duration_seconds']; }
        if (!isset($snapshot['entries']) || !is_array($snapshot['entries'])) { return null; }
        $total = 0;
        foreach ($snapshot['entries'] as $entry) {
            if (!isset($entry['duration']) || !is_int($entry['duration']) || $entry['duration'] < 0 || $total > PHP_INT_MAX - $entry['duration']) { return null; }
            $total += $entry['duration'];
        }
        return $total;
    }

    // Local-only operation, requiring explicit manual deletion evidence and exact entry identity.
    /**
     * Close only a manually verified deleted test concept, preserving invoice and entry snapshots.
     * Validates the exact entry set and other claims before releasing this invoice locally.
     * Does not delete anything remotely or remove Toggl tags.
     * @param list<int|string> $expectedIds Complete, manually checked entry set.
     * @throws InvoiceException If lifecycle, identity or ownership checks fail.
     */
    public function closeDeletedTest(int $invoiceId,string $project,string $remoteId,string $code,array $expectedIds,string $reason): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->pdo->prepare('SELECT * FROM invoices WHERE id=?');$q->execute([$invoiceId]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
            if(!$invoice||$invoice['lifecycle']!=='active'||$invoice['project_name']!==$project||$invoice['wefact_invoice_id']!==$remoteId||$invoice['wefact_invoice_code']!==$code||trim($reason)===''){throw new InvoiceException('Factuuridentiteit/status komt niet overeen.');}
            $q=$this->pdo->prepare('SELECT toggl_time_entry_id FROM invoiced_entries WHERE invoice_id=? AND released_at IS NULL');$q->execute([$invoiceId]);$ids=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));$expectedIds=array_map('strval',$expectedIds);sort($ids);sort($expectedIds);
            if($ids===[]||$ids!==$expectedIds){throw new InvoiceException('Factuurentries komen niet overeen.');}
            $check=$this->pdo->prepare('SELECT 1 FROM invoiced_entries WHERE toggl_time_entry_id=? AND released_at IS NULL AND (invoice_id IS NULL OR invoice_id<>?)');
            $reserve=$this->pdo->prepare('SELECT token_hash FROM invoice_entry_reservations WHERE toggl_time_entry_id=?');
            foreach($ids as$id){$check->execute([$id,$invoiceId]);if($check->fetchColumn()){throw new InvoiceException('Entry is elders geregistreerd.');}$reserve->execute([$id]);$hash=$reserve->fetchColumn();if($hash!==false&&$hash!==$invoice['token_hash']){throw new InvoiceException('Entry is door een andere actie gereserveerd.');}}
            $q=$this->pdo->prepare('SELECT snapshot_json,state FROM invoice_actions WHERE token_hash=?');$q->execute([$invoice['token_hash']]);$action=$q->fetch(PDO::FETCH_ASSOC);$snapshot=json_decode($action['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$snapshotIds=array_map('strval',$snapshot['entry_ids']);sort($snapshotIds);
            if($action['state']!=='succeeded'||$snapshotIds!==$ids){throw new InvoiceException('Actiesnapshot komt niet overeen.');}
            $q=$this->pdo->prepare('SELECT toggl_time_entry_id FROM invoice_entry_reservations WHERE token_hash=?');$q->execute([$invoice['token_hash']]);
            if(array_diff(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)),$ids)!==[]){throw new InvoiceException('Actie reserveert onverwachte andere entries.');}
            $now=gmdate('c');
            $q=$this->pdo->prepare("UPDATE invoices SET lifecycle='manual_deleted_test',closed_at=?,closure_reason=? WHERE id=? AND lifecycle='active'");$q->execute([$now,$reason,$invoiceId]);
            $q=$this->pdo->prepare('UPDATE invoiced_entries SET released_at=? WHERE invoice_id=? AND released_at IS NULL');$q->execute([$now,$invoiceId]);
            $q=$this->pdo->prepare('DELETE FROM invoice_entry_reservations WHERE token_hash=?');$q->execute([$invoice['token_hash']]);
            // Original succeeded action, payload, diagnostics and entry snapshots are immutable audit data.
            $this->pdo->exec('COMMIT');
        }catch(Throwable $e){$this->pdo->exec('ROLLBACK');throw $e;}
    }

    public function history(): array
    {
        $joined="SELECT a.*,r.outcome,r.diagnostics_json,(SELECT count(*) FROM invoice_reconciliation_events e WHERE e.token_hash=a.token_hash AND e.operation='no_invoice') AS manual_resolved FROM invoice_actions a LEFT JOIN invoice_action_results r ON r.token_hash=a.token_hash ";
        $remoteStatus = [];
        foreach ($this->pdo->query('SELECT * FROM invoice_remote_status')->fetchAll(PDO::FETCH_ASSOC) as $row) { $remoteStatus[$row['invoice_id']] = $row; }
        return ['remote_status'=>$remoteStatus, 'tag_attempts'=>$this->pdo->query('SELECT * FROM toggl_tag_sync_attempts ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC),
            'invoices'=>$this->pdo->query("SELECT i.*,(SELECT count(*) FROM invoiced_entries e WHERE e.invoice_id=i.id AND e.released_at IS NULL) AS tag_total,(SELECT count(*) FROM invoiced_entries e WHERE e.invoice_id=i.id AND e.released_at IS NULL AND e.tag_synced=1) AS tag_synced_count,(SELECT count(*) FROM invoiced_entries e WHERE e.invoice_id=i.id AND e.released_at IS NULL AND e.tag_synced=0 AND e.tag_sync_state='failed') AS tag_failed_count,(SELECT count(*) FROM invoiced_entries e WHERE e.invoice_id=i.id AND e.released_at IS NULL AND e.tag_sync_state='unknown') AS tag_unknown_count FROM invoices i WHERE lifecycle='active' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC),
            'closed_invoices'=>$this->pdo->query("SELECT * FROM invoices WHERE lifecycle='manual_deleted_test' ORDER BY closed_at DESC")->fetchAll(PDO::FETCH_ASSOC),
            'uncertain'=>$this->pdo->query($joined."WHERE a.state='reconciliation_required' OR r.outcome='created_review' ORDER BY a.created_at DESC")->fetchAll(PDO::FETCH_ASSOC),
            'failed'=>$this->pdo->query($joined."WHERE r.outcome='rejected' OR EXISTS(SELECT 1 FROM invoice_reconciliation_events e WHERE e.token_hash=a.token_hash AND e.operation='no_invoice') ORDER BY a.created_at DESC")->fetchAll(PDO::FETCH_ASSOC)];
    }
}
