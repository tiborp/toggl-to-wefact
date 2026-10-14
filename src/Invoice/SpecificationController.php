<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Config\Config;
use App\View\View;
use Throwable;

/** Serves a read-only historical view without sessions, API clients or invoice creation services. */
final class SpecificationController
{
    public function __construct(private readonly Config $config, private readonly View $view, private readonly Specification $specifications) {}

    public function handle(): void
    {
        header('Cache-Control: no-store');
        $id = $_GET['invoice_id'] ?? null;
        $specification = null; $error = null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || filter_var($id, FILTER_VALIDATE_INT) === false) {
            http_response_code(400); $error = 'Ongeldig lokaal factuurnummer.';
        } else {
            try {
                $specification = $this->specifications->find((int)$id);
                if ($specification === null) { http_response_code(404); $error = 'Deze lokale factuur is niet gevonden.'; }
            } catch (Throwable) {
                http_response_code(500); $error = 'De lokale urenspecificatie kon niet worden gelezen. Probeer het later opnieuw.';
            }
        }
        $this->view->page($this->config->get('APP_NAME', 'Toggl → WeFact'), 'Urenspecificatie',
            'Historische gegevens uit de lokale factuurregistratie.', '/facturen/urenspecificatie', compact('specification', 'error'));
    }
}
