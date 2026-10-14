<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Config\Config;
use App\Toggl\TogglSession;
use App\View\View;
use DateTimeZone;
use Throwable;

/** Binds editable selection to the session dataset and rebuilds previews without external writes. */
final class PreviewController
{
    public function __construct(private readonly Config $config, private readonly View $view, private readonly PreviewService $previews) {}

    // Presentation target only; never trust POST as a redirect URL or project selection.
    public static function redirectLocation(mixed $target,array $validatedModes): string
    {
        $fragment='factuur-preview';
        if(is_string($target)&&preg_match('/^[1-9][0-9]{0,17}$/D',$target)&&array_key_exists($target,$validatedModes)){
            $fragment='preview-project-'.$target;
        }
        return '/factuur-preview#'.$fragment;
    }

    public function handle(): void
    {
        TogglSession::open($this->config);
        $hours = $_SESSION['toggl_hours'] ?? null;
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!TogglSession::validCsrf($_POST['csrf'] ?? null)) { http_response_code(403); $error = 'Ongeldige aanvraag. Open de pagina opnieuw.'; }
            else {
                try {
                    if ($hours === null || !is_string($_POST['dataset_id'] ?? null) || !hash_equals($hours['dataset_id'], $_POST['dataset_id'])) {
                        throw new PreviewException('Deze selectie hoort bij een oudere of ontbrekende urenopvraag. Open Uren opnieuw.');
                    }
                    $result = $this->previews->build($hours, $_POST['selected'] ?? [], $_POST['modes'] ?? []);
                    $_SESSION['invoice_preview'] = ['dataset_id' => $hours['dataset_id'], 'selection' => $result['selection'], 'modes' => $result['modes'], 'revision' => bin2hex(random_bytes(16)), 'model_hashes' => array_map([InvoiceRepository::class, 'modelHash'], $result['previews'])];
                    session_write_close();
                    header('Location: '.self::redirectLocation($_POST['preview_target'] ?? null,$result['modes']), true, 303);
                    return;
                } catch (PreviewException $exception) { http_response_code(400); $error = $exception->getMessage(); }
                catch (Throwable) { http_response_code(500); $error = 'Preview kon niet veilig worden opgebouwd. Controleer de lokale configuratie.'; }
            }
        }
        $state = $_SESSION['invoice_preview'] ?? null;
        if ($hours !== null && ($state['dataset_id'] ?? null) !== $hours['dataset_id']) { $state = null; }
        $overview = null; $projectOptions = []; $emptyMessage=null;
        if ($hours !== null) {
            try {
                $presentation = $this->previews->presentation($hours, $_SESSION['toggl_projects'] ?? [], new DateTimeZone($this->config->get('APP_TIMEZONE', 'Europe/Amsterdam')), $state);
                $overview = $presentation['overview']; $projectOptions = $presentation['projectOptions'];$emptyMessage=$presentation['emptyMessage'];
            } catch (Throwable) {
                $error ??= 'De eerdere selectie/configuratie is niet meer geldig. Geldige uren zijn opnieuw standaard geselecteerd; controleer de projectkoppelingen.';
                unset($_SESSION['invoice_preview']);
                try {
                    $presentation = $this->previews->presentation($hours, $_SESSION['toggl_projects'] ?? [], new DateTimeZone($this->config->get('APP_TIMEZONE', 'Europe/Amsterdam')));
                    $overview = $presentation['overview']; $projectOptions = $presentation['projectOptions'];$emptyMessage=$presentation['emptyMessage'];
                } catch (Throwable) { $overview = null; }
            }
        }
        $csrf = $_SESSION['csrf']; $quotas = $_SESSION['toggl_quota'] ?? [];
        $from = $hours['from'] ?? ''; $to = $hours['to'] ?? ''; $onlyUninvoiced = $hours['only_uninvoiced'] ?? true;
        session_write_close();
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'Factuur-preview', 'Eén Toggl-project = één afzonderlijke factuur-preview. Er wordt niets aangemaakt of verzonden.', '/factuur-preview', compact('hours','overview','projectOptions','csrf','quotas','error','from','to','onlyUninvoiced','emptyMessage'));
    }
}
