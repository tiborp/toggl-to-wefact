<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Config\Config;
use App\Toggl\TogglSession;
use App\View\View;
use Throwable;
/** Presents local invoice history and accepts explicit retries of secondary Toggl tagging. */
final class HistoryController
{
    public function __construct(private readonly Config $config,private readonly View $view,private readonly InvoiceRepository $repo,private readonly ?\Closure $syncTags=null){}
    public function handle(): void
    {
        TogglSession::open($this->config);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!TogglSession::validCsrf($_POST['csrf']??null)){http_response_code(403);$error='Ongeldige aanvraag. Tags zijn niet gewijzigd.';}
            else{
                try{
                    if(($_POST['action']??null)!=='sync_tags'||!is_string($_POST['invoice_id']??null)||!preg_match('/^[1-9][0-9]{0,17}$/D',$_POST['invoice_id'])||$this->syncTags===null){throw new InvoiceException('Ongeldige synchronisatieactie.');}
                    $tags=($this->syncTags)((int)$_POST['invoice_id'],true);
                    $_SESSION['invoice_message']='Factuur blijft succesvol. Toggl-tags: '.$tags['synced'].'/'.$tags['total'].' gesynchroniseerd.'.($tags['synced']<$tags['total']?' Openstaande tags zijn nog niet volledig gesynchroniseerd.':'');
                }catch(Throwable){$_SESSION['invoice_message']='De Toggl-tags konden niet volledig worden bijgewerkt. Probeer het later opnieuw. De factuur blijft succesvol geregistreerd.';}
                session_write_close();header('Location: /facturen',true,303);return;
            }
        }
        $csrf=$_SESSION['csrf'];$quotas=$_SESSION['toggl_quota']??[];
        $message=$_SESSION['invoice_message']??null;unset($_SESSION['invoice_message']);session_write_close();
        $error??=null;try{$history=$this->repo->history();}catch(Throwable){$error='Lokale factuurhistorie kon niet worden gelezen.';$history=['invoices'=>[],'uncertain'=>[]];}
        $this->view->page($this->config->get('APP_NAME','Toggl → WeFact'),'Factuurhistorie','Actieve conceptfacturen, openstaande controles en afgesloten factuuracties.','/facturen',compact('history','message','error','csrf','quotas'));
    }
}
