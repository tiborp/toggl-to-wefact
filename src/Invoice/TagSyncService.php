<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Toggl\{TogglClient,TimeEntryStatus};
use PDO;
use Throwable;

/** Secondary tag sync; each entry is committed independently of every later attempt. */
final class TagSyncService
{
    public function __construct(private readonly PDO $pdo,private readonly TogglClient $toggl,private readonly string $source,private readonly ?\Closure $refresh=null) {}

    private function active(int $invoiceId): array
    {
        $q=$this->pdo->prepare("SELECT i.*,a.source_fingerprint FROM invoices i JOIN invoice_actions a ON a.token_hash=i.token_hash WHERE i.id=? AND i.lifecycle='active' AND a.state='succeeded'");$q->execute([$invoiceId]);$invoice=$q->fetch(PDO::FETCH_ASSOC);
        if(!$invoice||!hash_equals($invoice['source_fingerprint'],$this->source)){throw new InvoiceException('Alleen actieve succesvolle facturen uit de huidige API-configuratie kunnen tags synchroniseren.');}
        return $invoice;
    }
    /**
     * Attempt additive tagging only after successful local invoice registration.
     * On retry, a complete fresh bulk read prevents overwriting assumptions from stale snapshots.
     * Each PUT/result is committed separately; later failure never undoes earlier successes.
     * At most 25 updates per round, no automatic retry; quota stops leave remaining entries pending.
     *
     * @param int $invoiceId Local invoice ID, not the WeFact identifier.
     * @return array{total: int, synced: int, failed: int, unknown: int, pending: int}
     * @throws InvoiceException If lifecycle/source validation or the retry preflight fails.
     */
    public function sync(int $invoiceId,bool $retry=false): array
    {
        $invoice=$this->active($invoiceId);
        $q=$this->pdo->prepare('SELECT * FROM invoiced_entries WHERE invoice_id=? AND released_at IS NULL AND tag_synced=0 ORDER BY id');$q->execute([$invoiceId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if($rows===[]){return $this->summary($invoiceId);}
        $known=[];
        if($retry){
            if($this->refresh===null){throw new InvoiceException('Voor hersynchronisatie is een verse bulk-urencontrole vereist.');}
            $fresh=($this->refresh)(['from'=>$invoice['period_from'],'to'=>$invoice['period_to']]);
            if(!($fresh['complete']??false)){throw new InvoiceException('De bulk-urencontrole is onvolledig; tags zijn niet gewijzigd.');}
            foreach($fresh['entries']as$entry){$known[(string)$entry['id']]=$entry;}
        }
        $requests=0;$stop=false;
        foreach($rows as$row){
            // The lock spans one PUT only. Previous successes survive any later rollback.
            $this->pdo->exec('BEGIN IMMEDIATE');
            try{
                $this->active($invoiceId);
                $q=$this->pdo->prepare('SELECT * FROM invoiced_entries WHERE id=? AND invoice_id=? AND released_at IS NULL AND tag_synced=0');$q->execute([$row['id'],$invoiceId]);$current=$q->fetch(PDO::FETCH_ASSOC);
                if(!$current){$this->pdo->exec('COMMIT');continue;}
                $entry=$retry?($known[(string)$row['toggl_time_entry_id']]??null):['workspace_id'=>$row['workspace_id'],'tags'=>json_decode($row['tags_json']??'[]',true,512,JSON_THROW_ON_ERROR)];
                $state='pending';$error=null;$diagnostic=null;
                if(!$entry||$entry['workspace_id']!==$row['workspace_id']||!is_int($row['workspace_id'])||$row['workspace_id']<=0){$state='failed';$error='Entry ontbreekt of workspace wijkt af; handmatig controleren.';}
                elseif(TimeEntryStatus::invoiceSources(['tags'=>$entry['tags']])!==[]){$state='synced';}
                elseif(!$stop&&$requests<25){
                    $requests++;
                    try{
                        $result=$this->toggl->addInvoiced($row['workspace_id'],$row['toggl_time_entry_id']);
                        $state=$result['state'];$stop=$result['stop'];
                    }catch(Throwable){$state='unknown';$stop=true;}
                    $diagnostic=$this->toggl->tagDiagnostic();
                    if($state==='failed'){$error='Toggl heeft de tagupdate afgewezen; zie diagnostiek.';}
                    elseif($state==='unknown'){$error='Uitkomst van Toggl-tagupdate onbekend; zie diagnostiek.';}
                }
                $set=$this->pdo->prepare('UPDATE invoiced_entries SET tag_synced=?,tag_sync_state=?,tag_sync_error=?,tag_sync_attempted_at=coalesce(?,tag_sync_attempted_at) WHERE id=? AND released_at IS NULL');
                $set->execute([$state==='synced'?1:0,$state,$error,$diagnostic['attempted_at']??null,$row['id']]);
                if($diagnostic!==null){$log=$this->pdo->prepare('INSERT INTO toggl_tag_sync_attempts(invoice_id,diagnostics_json,created_at) VALUES(?,?,?)');$log->execute([$invoiceId,json_encode($diagnostic,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),gmdate('c')]);}
                $this->pdo->exec('COMMIT');
            }catch(Throwable $e){$this->pdo->exec('ROLLBACK');throw $e;}
        }
        return $this->summary($invoiceId);
    }
    public function summary(int $invoiceId): array
    {
        $q=$this->pdo->prepare("SELECT count(*) AS total,coalesce(sum(tag_synced),0) AS synced,coalesce(sum(CASE WHEN tag_synced=0 AND tag_sync_state='failed' THEN 1 ELSE 0 END),0) AS failed,coalesce(sum(CASE WHEN tag_synced=0 AND tag_sync_state='unknown' THEN 1 ELSE 0 END),0) AS unknown,coalesce(sum(CASE WHEN tag_synced=0 AND tag_sync_state='pending' THEN 1 ELSE 0 END),0) AS pending FROM invoiced_entries WHERE invoice_id=? AND released_at IS NULL");$q->execute([$invoiceId]);return array_map('intval',$q->fetch(PDO::FETCH_ASSOC));
    }
}
