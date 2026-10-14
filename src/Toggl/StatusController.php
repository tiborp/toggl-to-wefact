<?php
declare(strict_types=1);
namespace App\Toggl;

use App\Config\Config;
use App\View\View;

/** Handles explicit connection checks and renders the last sanitized session result. */
final class StatusController
{
    public function __construct(private readonly Config $config, private readonly View $view) {}

    public function handle(): void
    {
        TogglSession::open($this->config);
        $token = $this->config->togglToken();
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!TogglSession::validCsrf($_POST['csrf'] ?? null)) {
                http_response_code(403);
                $error = 'Ongeldige aanvraag. Open de pagina opnieuw.';
            } elseif (isset($_POST['workspace']) && !is_string($_POST['workspace'])) {
                http_response_code(400);
                $error = 'Selecteer een geldige workspace.';
            } else {
                $quota = new Quota($_SESSION['toggl_quota'] ?? []);
                $client = new TogglClient($token, new CurlTransport(), $quota);
                $_SESSION['toggl_result'] = (new StatusService($client))->load($_POST['workspace'] ?? null);
                if ($_SESSION['toggl_result']['projects'] !== null) {
                    TogglSession::rememberProjects($_SESSION['toggl_result']['projects']);
                }
                $_SESSION['toggl_quota'] = $quota->state();
                session_write_close();
                header('Location: /toggl', true, 303);
                return;
            }
        }
        $status = $_SESSION['toggl_result'] ?? null;
        $quotas = $_SESSION['toggl_quota'] ?? [];
        $csrf = $_SESSION['csrf'];
        if ($token === '') { $error = 'TOGGL_API_TOKEN ontbreekt. Vul deze uitsluitend in je lokale .env in.'; }
        session_write_close();
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'Toggl-status', 'Gebruiker, workspaces en projecten read-only ophalen.', '/toggl', compact('status', 'quotas', 'csrf', 'error'));
    }
}
