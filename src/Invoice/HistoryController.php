<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Config\Config;
use App\Toggl\TogglSession;
use App\View\View;
use Throwable;
/** Presents local history and accepts explicit remote status reads or secondary tagging retries. */
final class HistoryController
{
    public function __construct(private readonly Config $config,private readonly View $view,private readonly InvoiceRepository $repo,private readonly ?\Closure $syncTags=null,private readonly ?\Closure $refreshStatus=null){}
    public function handle(): void
    {
        TogglSession::open($this->config);
        if($_SERVER['REQUEST_METHOD']==='POST'){
            if(!TogglSession::validCsrf($_POST['csrf']??null)){http_response_code(403);$error=($_POST['action']??null)==='refresh_status'?'Ongeldige aanvraag. Status is niet bijgewerkt.':'Ongeldige aanvraag. Tags zijn niet gewijzigd.';}
            else{
                if (($_POST['action'] ?? null) === 'refresh_status') {
                    try {
                        if ($this->refreshStatus === null) { throw new InvoiceException('Statuscontrole niet beschikbaar.'); }
                        $result = ($this->refreshStatus)();
                        $_SESSION['invoice_message'] = 'WeFact-status: '.$result['success'].' bijgewerkt, '.$result['failed'].' mislukt en '.$result['skipped'].' overgeslagen.'.($result['stopped'] ? ' De controle is gestopt; resterende facturen zijn niet gecontroleerd.' : '');
                    } catch (Throwable) {
                        $_SESSION['invoice_message'] = 'De statuscontrole kon niet worden afgerond. Eerdere statusgegevens en factuurregistraties blijven behouden.';
                    }
                    session_write_close(); header('Location: /facturen', true, 303); return;
                }
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
        $this->view->page($this->config->get('APP_NAME','Toggl → WeFact'),'Factuurhistorie','Succesvolle facturen met laatst bekende WeFact-status, openstaande controles en afgesloten factuuracties.','/facturen',compact('history','message','error','csrf','quotas'));
    }
}
