<?php
declare(strict_types=1);
namespace App\Toggl;

use App\Config\Config;
use App\View\View;
use App\Invoice\{PreviewService, PreviewException};
use DateTimeZone;

/** Fetches period data explicitly and applies presentation filters locally to the session dataset. */
final class TimeEntryController
{
    public function __construct(private readonly Config $config, private readonly View $view, private readonly ?HttpTransport $http = null, private readonly ?PreviewService $previews = null) {}

    public function handle(): void
    {
        TogglSession::open($this->config);
        $timezone = new DateTimeZone($this->config->get('APP_TIMEZONE', 'Europe/Amsterdam'));
        $earliestStart = DateRange::earliestStart($timezone);
        $minimumDate = $earliestStart->format('Y-m-d');
        $error = null;
        $hours = $_SESSION['toggl_hours'] ?? null;
        $from = $hours['from'] ?? '';
        $to = $hours['to'] ?? '';
        $onlyUninvoiced = $hours['only_uninvoiced'] ?? true;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!TogglSession::validCsrf($_POST['csrf'] ?? null)) {
                http_response_code(403);
                $error = 'Ongeldige aanvraag. Open de pagina opnieuw.';
            } else {
                $from = is_string($_POST['from'] ?? null) ? $_POST['from'] : '';
                $to = is_string($_POST['to'] ?? null) ? $_POST['to'] : '';
                $onlyUninvoiced = isset($_POST['only_uninvoiced']);
                try {
                    $range = DateRange::fromInput($_POST['from'] ?? null, $_POST['to'] ?? null, $timezone);
                    $range->assertAvailableFrom($earliestStart);
                    if (isset($_POST['only_uninvoiced']) && $_POST['only_uninvoiced'] !== '1') {
                        throw new TogglException('Ongeldige filterwaarde.');
                    }
                    $action = $_POST['action'] ?? 'fetch';
                    if (!is_string($action) || !in_array($action, ['fetch', 'filter'], true)) { throw new TogglException('Ongeldige actie.'); }
                    $projectFilter = $_POST['project_filter'] ?? '';
                    if (!is_string($projectFilter) || ($projectFilter !== '' && $projectFilter !== 'none' && !preg_match('/^[1-9][0-9]{0,17}$/D', $projectFilter))) { throw new TogglException('Ongeldig projectfilter.'); }
                    if ($action === 'filter') {
                        if ($hours === null || $hours['from'] !== $range->from || $hours['to'] !== $range->to) {
                            throw new TogglException('Haal eerst uren op voor deze periode voordat je alleen het filter wijzigt.');
                        }
                        $hours['only_uninvoiced'] = $onlyUninvoiced;
                        $hours['show_archived']=isset($_POST['show_archived'])&&$_POST['show_archived']==='1';
                        if (($hours['project_filter'] ?? '') !== $projectFilter) { unset($_SESSION['invoice_preview']); }
                        $hours['project_filter'] = $projectFilter;
                    } else {
                        $quota = new Quota($_SESSION['toggl_quota'] ?? []);
                        $client = new TogglClient($this->config->togglToken(), $this->http ?? new CurlTransport(), $quota);
                        $result = (new TimeEntryReader($client))->read($range);
                        $projects = $_SESSION['toggl_projects'] ?? [];
                        // Reuse known project names; fetch the catalog once only if IDs are missing.
                        $missing = array_filter($result['entries'], static fn ($entry) => $entry['project_id'] !== null && !isset($projects[$entry['project_id']]));
                        $projectWarning = null;
                        if ($missing !== [] && empty($_SESSION['toggl_projects_complete'])) {
                            try {
                                TogglSession::rememberProjects($client->userProjects());
                                $_SESSION['toggl_projects_complete'] = true;
                            } catch (TogglException $exception) {
                                $projectWarning = 'Projectnamen niet volledig beschikbaar: ' . $exception->getMessage();
                            }
                        }
                        $_SESSION['toggl_quota'] = $quota->state();
                        $hours = $result + ['from' => $range->from, 'to' => $range->to, 'only_uninvoiced' => $onlyUninvoiced, 'loaded_at' => date('c'), 'project_warning' => $projectWarning, 'dataset_id' => bin2hex(random_bytes(16)), 'project_filter' => $projectFilter,'show_archived'=>isset($_POST['show_archived'])&&$_POST['show_archived']==='1'];
                        unset($_SESSION['invoice_preview']);
                    }
                    $_SESSION['toggl_hours'] = $hours;
                    session_write_close();
                    header('Location: /uren', true, 303);
                    return;
                } catch (TogglException $exception) {
                    http_response_code(400);
                    $error = $exception->getMessage();
                }
            }
        }
        $overview = $hours !== null ? (new TimeEntryOverview())->build($hours['entries'], $_SESSION['toggl_projects'] ?? [], $hours['only_uninvoiced'], $timezone) : null;
        $projectOptions = ProjectChoice::options($hours['entries'] ?? [],$_SESSION['toggl_projects'] ?? [],$hours['show_archived']??false,$hours['project_filter']??'');
        if($hours===null&&$this->previews!==null){$projectOptions=$this->previews->projectChoices([], $_SESSION['toggl_projects']??[]);}
        if ($hours !== null && $this->previews !== null) {
            $state = $_SESSION['invoice_preview'] ?? null;
            if (($state['dataset_id'] ?? null) !== ($hours['dataset_id'] ?? null)) { $state = null; }
            try {
                $presentation = $this->previews->presentation($hours, $_SESSION['toggl_projects'] ?? [], $timezone, $state);
                $overview = $presentation['overview']; $projectOptions = $presentation['projectOptions'];$emptyMessage=$presentation['emptyMessage'];
            } catch (PreviewException $exception) {
                $error ??= $exception->getMessage() . ' De eerdere selectie is vervallen; geldige uren zijn opnieuw standaard geselecteerd.';
                unset($_SESSION['invoice_preview']);
                try {
                    $presentation = $this->previews->presentation($hours, $_SESSION['toggl_projects'] ?? [], $timezone);
                    $overview = $presentation['overview']; $projectOptions = $presentation['projectOptions'];$emptyMessage=$presentation['emptyMessage'];
                } catch (PreviewException) { $overview = null; }
            }
        }
        $emptyMessage??=$hours!==null?ProjectChoice::emptyMessage($hours):null;
        $quotas = $_SESSION['toggl_quota'] ?? [];
        $csrf = $_SESSION['csrf'];
        session_write_close();
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'Uren', 'Read-only urenoverzicht. Kies een periode; van en tot zijn inclusief.', '/uren', compact('hours', 'overview', 'quotas', 'csrf', 'error', 'from', 'to', 'onlyUninvoiced', 'projectOptions','emptyMessage','minimumDate'));
    }
}
