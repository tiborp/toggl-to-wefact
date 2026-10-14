<?php
declare(strict_types=1);
namespace App\Project;

use App\Config\Config;
use App\Toggl\{TogglSession, TogglClient, CurlTransport, HttpTransport, Quota, TogglException};
use App\WeFact\{DebtorCache, WeFactException};
use App\View\View;
use PDO;
use Throwable;

/** Manages local project configuration and explicit catalog refreshes with CSRF protection. */
final class ProjectController
{
    public function __construct(private readonly Config $config, private readonly View $view, private readonly PDO $pdo, private readonly ?HttpTransport $http = null) {}

    public function handle(): void
    {
        TogglSession::open($this->config);
        $togglFingerprint = hash('sha256', $this->config->togglToken());
        $wefactFingerprint = hash('sha256', $this->config->wefactKey());
        $projects = new ProjectRepository($this->pdo);
        $service = new ProjectService($projects, new MappingRepository($this->pdo), new DebtorCache($this->pdo), $togglFingerprint, $wefactFingerprint);
        $csrf = $_SESSION['csrf'];
        $error = null;
        $success = $_SESSION['project_flash'] ?? null;
        unset($_SESSION['project_flash']);
        $search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $showArchived=($_GET['show_archived']??null)==='1';
        $params=[];if($search!==''){$params['q']=$search;}if($showArchived){$params['show_archived']='1';}
        $projectUrl='/projecten'.($params!==[]?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');
        $formValues = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!TogglSession::validCsrf($_POST['csrf'] ?? null)) {
                http_response_code(403);
                $error = 'Ongeldige aanvraag. Open de pagina opnieuw.';
            } else {
                try {
                    $action = $_POST['action'] ?? null;
                    if ($action === 'sync') {
                        $quota = new Quota($_SESSION['toggl_quota'] ?? []);
                        try {
                            $client = new TogglClient($this->config->togglToken(), $this->http ?? new CurlTransport(), $quota);
                            $count = (new ProjectSync($client, $projects))->refresh($togglFingerprint);
                            $success = $count . ' Toggl-projecten vernieuwd. Bestaande mappings zijn behouden.';
                        } finally { $_SESSION['toggl_quota'] = $quota->state(); }
                    } elseif ($action === 'save') {
                        $service->save($_POST['project_id'] ?? null, $_POST['debtor_id'] ?? null, $_POST['rate'] ?? null);
                        $success = 'Projectconfiguratie opgeslagen.';
                    } elseif ($action === 'remove') {
                        $service->remove($_POST['project_id'] ?? null);
                        $success = 'Alleen de lokale projectconfiguratie is verwijderd.';
                    } else { throw new ProjectException('Ongeldige actie.'); }
                    $_SESSION['project_flash'] = $success;
                    session_write_close();
                    header('Location: '.$projectUrl, true, 303);
                    return;
                } catch (ProjectException | TogglException | WeFactException $exception) {
                    http_response_code(400); $error = $exception->getMessage();
                } catch (Throwable) {
                    http_response_code(500); $error = 'De lokale projectactie kon niet worden uitgevoerd. Bestaande gegevens blijven behouden.';
                }
                if (($_POST['action'] ?? null) === 'save') {
                    $formValues = [];
                    foreach (['project_id', 'debtor_id', 'rate'] as $field) { $formValues[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : ''; }
                }
            }
        }
        $quotas = $_SESSION['toggl_quota'] ?? [];
        session_write_close();
        try { $overview = $service->overview($search);
            $overview['projects']=array_values(array_filter($overview['projects'],static fn($p)=>\App\Toggl\ProjectChoice::visible((bool)$p['active'],$showArchived)));
        }
        catch (Throwable) { $error ??= 'De lokale project- of debiteurencache kon niet worden gelezen.'; $overview = ['projects' => [], 'debtors' => [], 'sync' => null, 'debtor_metadata' => null]; }
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'Projectkoppelingen', 'Beheer lokaal de WeFact-koppeling en het tarief per Toggl-project.', '/projecten', compact('overview', 'csrf', 'error', 'success', 'search', 'quotas', 'formValues','showArchived','projectUrl'));
    }
}
