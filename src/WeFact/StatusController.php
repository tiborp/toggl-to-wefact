<?php
declare(strict_types=1);
namespace App\WeFact;

use App\Config\Config;
use App\View\View;

/** Separates explicit debtor refresh from local cache browsing and connection feedback. */
final class StatusController
{
    public function __construct(private readonly Config $config, private readonly View $view, private readonly DebtorCache $cache, private readonly ?HttpTransport $http = null) {}

    public function handle(): void
    {
        // Independent of Toggl; reuse only PHP's existing local session storage.
        ini_set('session.save_path', dirname(__DIR__, 2) . '/storage');
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
        header('Cache-Control: no-store');
        $key = $this->config->wefactKey();
        $configured = $key !== '';
        $fingerprint = hash('sha256', $key);
        if (($_SESSION['wefact_fingerprint'] ?? '') !== $fingerprint) {
            unset($_SESSION['wefact_status']);
            $_SESSION['wefact_fingerprint'] = $fingerprint;
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf'];
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
                http_response_code(403);
                $error = 'Ongeldige aanvraag. Open de pagina opnieuw.';
            } elseif (($_POST['action'] ?? null) !== 'refresh') {
                http_response_code(400);
                $error = 'Ongeldige actie.';
            } else {
                $client = new WeFactClient($key, $this->http ?? new CurlTransport());
                $_SESSION['wefact_status'] = (new DebtorService($client, $this->cache))->refresh($fingerprint);
                session_write_close();
                header('Location: /wefact', true, 303);
                return;
            }
        }
        $status = $_SESSION['wefact_status'] ?? null;
        session_write_close();
        $search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        try { $cached = $this->cache->read($fingerprint, $search); }
        catch (WeFactException $exception) { $error = $exception->getMessage(); $cached = ['metadata' => null, 'debtors' => []]; }
        if (!$configured) { $error = 'WEFACT_API_KEY ontbreekt. Vul deze uitsluitend in je lokale .env in.'; }
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'WeFact-status', 'Debiteuren read-only bekijken vanuit de lokale cache.', '/wefact', compact('configured', 'status', 'cached', 'csrf', 'error', 'search'));
    }
}
