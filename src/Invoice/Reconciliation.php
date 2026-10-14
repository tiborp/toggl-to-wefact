<?php
declare(strict_types=1);
namespace App\Invoice;
use PDO;
use Throwable;

/** Resolves uncertain writes from explicit manual evidence without making external API calls. */
final class Reconciliation
{
    public function __construct(private readonly PDO $pdo){}
    /**
     * Apply manual evidence to an uncertain action; never infer remote state from local absence.
     * no_invoice cancels and releases; link_existing registers the original snapshot unchanged.
     * Both paths require the exact claimed entry set and append audit evidence transactionally.
     *
     * @param string $hash Stored action token hash, not the browser confirmation token.
     * @param list<int|string> $expectedIds Complete entry set checked by the operator.
     * @param int|null $amount Manually verified amount excluding VAT, in eurocents.
     * @throws InvoiceException If evidence conflicts with the action, claims or existing invoices.
     */
    public function resolve(string $hash,string $operation,array $expectedIds,string $reason,?string $remoteId=null,?string $code=null,?int $amount=null,?string $debtor=null):void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$hash)||!in_array($operation,['no_invoice','link_existing'],true)||trim($reason)===''){throw new InvoiceException('Actie-ID, expliciete herstelactie en auditreden zijn verplicht.');}
        $this->pdo->beginTransaction();
        try{
            // Obtain a writer lock before validating reservations and lifecycle.
            $this->pdo->exec('UPDATE invoice_actions SET updated_at=updated_at WHERE 0');
            $q=$this->pdo->prepare("SELECT * FROM invoice_actions WHERE token_hash=? AND state='reconciliation_required'");$q->execute([$hash]);$action=$q->fetch(PDO::FETCH_ASSOC);
            if(!$action){throw new InvoiceException('Alleen een openstaande reconciliation_required-actie kan worden hersteld.');}
            $model=json_decode($action['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if(InvoiceRepository::modelHash($model)!==$action['preview_hash']){throw new InvoiceException('Factuursnapshot wijkt af van de vastgelegde preview.');}
            $ids=array_map('strval',$model['entry_ids']);$expectedIds=array_map('strval',$expectedIds);sort($ids);sort($expectedIds);
            $q=$this->pdo->prepare('SELECT toggl_time_entry_id FROM invoice_entry_reservations WHERE token_hash=?');$q->execute([$hash]);$reserved=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));sort($reserved);
            if(!$ids||count(array_unique($ids))!==count($ids)||$ids!==$expectedIds||$ids!==$reserved||array_map('strval',array_column($model['entries'],'id'))!==array_map('strval',$model['entry_ids'])){throw new InvoiceException('Opgegeven entries, snapshot en reserveringen komen niet exact overeen.');}
            $q=$this->pdo->prepare('SELECT 1 FROM invoices WHERE token_hash=?');$q->execute([$hash]);if($q->fetchColumn()){throw new InvoiceException('Deze actie heeft al een factuurregistratie.');}
            $q=$this->pdo->prepare('SELECT 1 FROM invoiced_entries WHERE toggl_time_entry_id=? AND released_at IS NULL');foreach($ids as$id){$q->execute([$id]);if($q->fetchColumn()){throw new InvoiceException('Een entry is al elders lokaal gefactureerd.');}}
            if($operation==='no_invoice'){
                if(!empty($action['wefact_invoice_id'])||!empty($action['wefact_invoice_code'])){throw new InvoiceException('Bekende WeFact-identiteit: vrijgave geweigerd. Controleer eerst de bestaande factuur.');}
                $q=$this->pdo->prepare("UPDATE invoice_actions SET state='cancelled',message=?,updated_at=? WHERE token_hash=?");$q->execute(['Handmatig gecontroleerd en afgesloten: '.$reason,gmdate('c'),$hash]);
                $q=$this->pdo->prepare('DELETE FROM invoice_entry_reservations WHERE token_hash=?');$q->execute([$hash]);
            }else{
                if(!is_string($remoteId)||!preg_match('/^[1-9][0-9]{0,17}$/D',$remoteId)||!is_string($code)||trim($code)===''||$amount!==$model['total_cents']||$debtor!==$model['wefact_debtor_id']){throw new InvoiceException('Bestaand concept-ID/nummer, gecontroleerd bedrag en debiteur moeten overeenkomen.');}
                if((!empty($action['wefact_invoice_id'])&&$action['wefact_invoice_id']!==$remoteId)||(!empty($action['wefact_invoice_code'])&&$action['wefact_invoice_code']!==$code)){throw new InvoiceException('Bekende WeFact-identiteit wijkt af.');}
                $q=$this->pdo->prepare('SELECT 1 FROM invoices WHERE wefact_invoice_id=?');$q->execute([$remoteId]);if($q->fetchColumn()){throw new InvoiceException('WeFact-concept is al lokaal geregistreerd.');}
                // Historical snapshots remain historical; never recalculate using current policy/rate.
                $model['billed_duration_seconds']??=$model['duration_seconds']??InvoiceRepository::snapshotDuration($model);
                if(!is_int($model['billed_duration_seconds'])){throw new InvoiceException('Onvolledige tijdsnapshot.');}
                foreach($model['entries']as$entry){if(!is_int($entry['duration'])||$entry['duration']<0||$entry['stop']===null||$entry['project_id']!==$model['toggl_project_id']){throw new InvoiceException('Ongeldige oorspronkelijke factuurentry.');}}
                $payload=json_decode($action['payload_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_string($payload['Date']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$payload['Date'])){throw new InvoiceException('Oorspronkelijke factuurdatum ontbreekt.');}
                (new InvoiceRepository($this->pdo))->completeHash($hash,$action['owner_hash'],$model,['id'=>$remoteId,'code'=>$code],$action['source_fingerprint'],$payload['Date']);
            }
            $q=$this->pdo->prepare('INSERT INTO invoice_reconciliation_events(token_hash,operation,reason,previous_message,entry_ids_json,wefact_invoice_id,wefact_invoice_code,created_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$hash,$operation,$reason,$action['message'],json_encode($ids),$remoteId,$code,gmdate('c')]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}throw $e;}
    }
}
