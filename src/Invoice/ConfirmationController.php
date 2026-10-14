<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Config\Config;
use App\Toggl\TogglSession;
use App\View\View;
use Throwable;

/** Handles session-bound prepare, confirm and cancel actions; only confirm creates a remote draft. */
final class ConfirmationController
{
    public function __construct(private readonly Config $config,private readonly View $view,private readonly InvoiceService $service,private readonly InvoiceRepository $repo,private readonly ?\Closure $syncTags=null){}
    public function handle(): void
    {
        TogglSession::open($this->config);
        $owner=hash('sha256',session_id());$hours=$_SESSION['toggl_hours']??null;$state=$_SESSION['invoice_preview']??null;
        $error=null;$model=null;$payload=null;$action=null;
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!TogglSession::validCsrf($_POST['csrf']??null)){http_response_code(403);$error='Ongeldige aanvraag. Open de pagina opnieuw.';}
            else{
                try{
                    $operation=$_POST['action']??null;
                    if($operation==='prepare'){
                        unset($_SESSION['invoice_confirmation']);
                        if($hours===null||$state===null||!is_string($_POST['project_id']??null)||!preg_match('/^[1-9][0-9]{0,17}$/D',$_POST['project_id'])){throw new InvoiceException('Werk eerst een geldige project-preview bij.');}
                        if($this->config->wefactKey()===''){throw new InvoiceException('WEFACT_API_KEY ontbreekt.');}
                        $key=($state['revision']??$hours['dataset_id']).':'.$_POST['project_id'].':'.($state['model_hashes'][(int)$_POST['project_id']]??'');
                        $token=$_SESSION['invoice_tokens'][$key]??null;
                        if($token===null){
                            $token=bin2hex(random_bytes(32));$this->service->prepare($token,$owner,$hours,$state,(int)$_POST['project_id']);$_SESSION['invoice_tokens'][$key]=$token;
                        }
                        if($this->repo->action($token,$owner)['state']!=='prepared'){
                            unset($_SESSION['invoice_tokens'][$key],$_SESSION['invoice_preview'],$_SESSION['invoice_confirmation']);
                            throw new InvoiceException('Deze bevestiging is afgesloten of al gebruikt. Werk eerst de factuur-preview opnieuw bij.');
                        }
                        $_SESSION['invoice_confirmation']=$token;
                    }elseif($operation==='confirm'){
                        $token=$_POST['token']??null;
                        if(!is_string($token)||$token!==($_SESSION['invoice_confirmation']??null)||$hours===null||$state===null){throw new InvoiceException('Bevestiging hoort niet bij deze sessie/preview.');}
                        $id=$this->service->confirm($token,$owner,$hours,$state);
                        $_SESSION['invoice_message']='WeFact-conceptfactuur succesvol aangemaakt en lokaal geregistreerd.';
                        if($this->syncTags!==null){
                            try{$tags=($this->syncTags)($id,false);$_SESSION['invoice_message'].=' Toggl-tags: '.$tags['synced'].'/'.$tags['total'].' gesynchroniseerd.'.($tags['synced']<$tags['total']?' De factuur is succesvol; openstaande tags kunnen via de historie opnieuw worden gesynchroniseerd.':'');}
                            catch(Throwable){$_SESSION['invoice_message'].=' Toggl-tagging kon niet worden afgerond. De factuur blijft succesvol; probeer tags later via de historie opnieuw te synchroniseren.';}
                        }
                        unset($_SESSION['invoice_preview'],$_SESSION['invoice_confirmation']);
                        session_write_close();header('Location: /facturen',true,303);return;
                    }elseif($operation==='cancel'){
                        if(!is_string($_POST['token']??null)||$_POST['token']!==($_SESSION['invoice_confirmation']??null)){throw new InvoiceException('Annulering hoort niet bij deze bevestiging.');}
                        if(isset($_SESSION['invoice_confirmation'])){$this->repo->cancel($_SESSION['invoice_confirmation'],$owner);unset($_SESSION['invoice_confirmation'],$_SESSION['invoice_preview']);}
                        session_write_close();header('Location: /factuur-preview#factuur-preview',true,303);return;
                    }else{throw new InvoiceException('Ongeldige factuuractie.');}
                    session_write_close();header('Location: /concept-bevestigen',true,303);return;
                }catch(Throwable $e){
                    $_SESSION['invoice_error']=$e instanceof InvoiceException||$e instanceof PreviewException?$e->getMessage():'Factuuractie kon niet veilig worden uitgevoerd. Controleer historie; probeer bij een onzekere write niet opnieuw.';
                    session_write_close();header('Location: /concept-bevestigen',true,303);return;
                }
            }
        }
        $error??=$_SESSION['invoice_error']??null;unset($_SESSION['invoice_error']);
        if(isset($_SESSION['invoice_confirmation'])){
            try{$action=$this->repo->action($_SESSION['invoice_confirmation'],$owner);if($action['state']!=='prepared'){$error??='Deze bevestiging is afgesloten of al gebruikt. Controleer de historie en werk voor een nieuwe selectie de preview opnieuw bij.';unset($_SESSION['invoice_confirmation']);$action=null;}
                if($action!==null){$model=json_decode($action['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$payload=json_decode($action['payload_json'],true,512,JSON_THROW_ON_ERROR);}}catch(Throwable){$error??='Bevestiging kon niet worden gelezen.';}
        }
        $csrf=$_SESSION['csrf'];$token=$_SESSION['invoice_confirmation']??'';
        session_write_close();
        $this->view->page($this->config->get('APP_NAME','Toggl → WeFact'),'Conceptfactuur bevestigen','Deze laatste bevestiging maakt precies één projectconcept aan in WeFact. Er wordt nooit verzonden.','/concept-bevestigen',compact('action','model','payload','csrf','token','error'));
    }
}
