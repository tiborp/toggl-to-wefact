<?php
declare(strict_types=1);
namespace App\Invoice;

use App\WeFact\WeFactClient;
use App\WeFact\WeFactException;
use Closure;
use Throwable;

/** Coordinates preview validation, the single WeFact write and authoritative local registration. */
final class InvoiceService
{
    public function __construct(private readonly PreviewService $previews,private readonly InvoiceRepository $repo,private readonly WeFactClient $wefact,private readonly Closure $fresh,private readonly string $source) {}
    /**
     * Persist a session-owned confirmation snapshot without contacting WeFact.
     * @throws InvoiceException If the preview is stale, incomplete or not representable as API lines.
     */
    public function prepare(string $token,string $owner,array $hours,array $state,int $project): array
    {
        $model=$this->validated($hours,$state,$project);
        $payload=(new InvoicePayload())->build($model,date('Y-m-d')); // Fail before even presenting a write for an unrepresentable amount.
        $this->repo->prepare($token,$owner,$hours['dataset_id'],$this->source,$model,$payload,$state['revision']??'');
        return $model;
    }
    /**
     * Revalidate against fresh Toggl data, claim entries, then attempt exactly one invoice/add.
     * Toggl reads precede the write; local claims are committed before contacting WeFact.
     * A failed call may already have created a remote invoice, so uncertainty retains claims.
     *
     * @return int Local invoice ID after confirmed remote success and local registration.
     * @throws InvoiceException If validation fails or the write cannot be safely registered.
     */
    public function confirm(string $token,string $owner,array $hours,array $state): int
    {
        $action=$this->repo->action($token,$owner);
        if($action['state']!=='prepared'){throw new InvoiceException('Bevestiging al verwerkt of onzeker. Controleer de factuurhistorie; geen nieuwe API-call.');}
        if($action['dataset_id']!==$hours['dataset_id'] || $action['source_fingerprint']!==$this->source){throw new InvoiceException('Dataset/API-configuratie gewijzigd. Maak een nieuwe preview.');}
        $model=$this->validated($hours,$state,(int)$action['toggl_project_id']);
        if(InvoiceRepository::modelHash($model)!==$action['preview_hash']){throw new InvoiceException('Configuratie of entrydata is gewijzigd sinds de bevestiging. Maak een nieuwe preview.');}
        $fresh=($this->fresh)($hours);
        if(!($fresh['complete']??false)){throw new InvoiceException('De actuele Toggl-controle is onvolledig of niet bereikbaar. Er is geen factuur aangemaakt.');}
        $current=$hours;$current['entries']=$fresh['entries'];$current['complete']=true;
        $rebuilt=$this->previews->build($current,[$model['toggl_project_id']=>array_map('strval',$model['entry_ids'])],[$model['toggl_project_id']=>$model['mode']])['previews'][$model['toggl_project_id']]??null;
        if($rebuilt===null || InvoiceRepository::modelHash($rebuilt)!==$action['preview_hash']){throw new InvoiceException('Actuele Toggl-entries of lokale configuratie wijken af van de bevestigde preview. Haal uren op en maak een nieuwe preview.');}
        $date=date('Y-m-d');
        if((json_decode($action['payload_json'],true)['Date']??null)!==$date){throw new InvoiceException('De factuurdatum is gewijzigd. Maak een nieuwe bevestiging.');}
        $payload=(new InvoicePayload())->build($rebuilt,$date);
        $this->repo->claim($token,$owner,$rebuilt,$payload,function()use($current,$model,$action):void{
            $locked=$this->previews->build($current,[$model['toggl_project_id']=>array_map('strval',$model['entry_ids'])],[$model['toggl_project_id']=>$model['mode']])['previews'][$model['toggl_project_id']]??null;
            if($locked===null||InvoiceRepository::modelHash($locked)!==$action['preview_hash']){throw new InvoiceException('Configuratie is vlak voor de write gewijzigd. Maak een nieuwe preview.');}
        });
        $remote=null;
        try{
            $remote=$this->wefact->createDraft($payload);
            if($remote['status']!==0 || $remote['amount_cents']!==$rebuilt['total_cents'] || $remote['debtor']!==$rebuilt['wefact_debtor_id'] || $remote['sent']!==0 || $remote['currency']!=='EUR'){
                throw new InvoiceException('WeFact gaf een afwijkende status, debiteur of AmountExcl terug. Concept/bedrag handmatig controleren; nooit blind opnieuw aanmaken.');
            }
            $id=$this->repo->complete($token,$owner,$rebuilt,$remote,$this->source,$date);
            $this->repo->result($token,'success',$this->wefact->invoiceDiagnostics()+['phase_result'=>'registered']);
            return $id;
        }catch(Throwable $e){
            $diagnostics=$e instanceof WeFactException?$e->diagnostics:$this->wefact->invoiceDiagnostics();
            $diagnostics['exception_class']=get_class($e);
            if($e instanceof WeFactException&&$e->outcome==='rejected'){
                $this->repo->rejected($token,$owner,$e->getMessage(),$diagnostics);
                throw new InvoiceException($e->getMessage());
            }
            $message=$e instanceof InvoiceException||$e instanceof WeFactException?$e->getMessage():'WeFact-write heeft geen betrouwbaar bevestigd resultaat. Handmatige controle vereist; niet opnieuw aanmaken.';
            if($remote!==null){
                $diagnostics['remote_invoice']=$remote;
                $diagnostics['phase_result']='created_but_review_required';
                $this->repo->createdReview($token,$message,$remote,$diagnostics);
            }else{
                $this->repo->uncertain($token,$message);
                $this->repo->result($token,'unknown',$diagnostics);
            }
            throw new InvoiceException($message);
        }
    }
    private function validated(array $hours,array $state,int $project): array
    {
        if(!($hours['complete']??false)||($state['dataset_id']??null)!==$hours['dataset_id']){throw new InvoiceException('Volledige, huidige en expliciet bijgewerkte preview ontbreekt.');}
        $model=$this->previews->build($hours,[$project=>$state['selection'][$project]??[]],[$project=>$state['modes'][$project]??'summary'])['previews'][$project]??null;
        if($model===null || !isset($state['model_hashes'][$project]) || !hash_equals($state['model_hashes'][$project],InvoiceRepository::modelHash($model))){throw new InvoiceException('Preview ontbreekt of configuratie/entrydata is gewijzigd. Werk de preview opnieuw bij.');}
        return $model;
    }
}
