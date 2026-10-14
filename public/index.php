<?php
declare(strict_types=1);

// Wire shared local state and transports; only explicit controller actions may call external APIs.

use App\Http\Router;
use App\View\View;

try {
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $view = new View(dirname(__DIR__) . '/templates');
    $router = new Router();
    $pages = [
        '/' => ['Toggl → WeFact', 'Haal uren op, controleer de selectie en maak per project een WeFact-conceptfactuur.'],
    ];
    foreach ($pages as $path => [$title, $message]) {
        $router->get($path, fn () => $view->page($app['config']->get('APP_NAME', 'Toggl → WeFact'), $title, $message, $path));
    }
    $toggl = new \App\Toggl\StatusController($app['config'], $view);
    $router->get('/toggl', fn () => $toggl->handle());
    $router->post('/toggl', fn () => $toggl->handle());
    $projectService = new \App\Project\ProjectService(new \App\Project\ProjectRepository($app['pdo']), new \App\Project\MappingRepository($app['pdo']), new \App\WeFact\DebtorCache($app['pdo']), hash('sha256', $app['config']->togglToken()), hash('sha256', $app['config']->wefactKey()));
    $previewService = new \App\Invoice\PreviewService($projectService, new \App\Invoice\LocalInvoiceStatus($app['pdo']));
    $hours = new \App\Toggl\TimeEntryController($app['config'], $view, previews: $previewService);
    $router->get('/uren', fn () => $hours->handle());
    $router->post('/uren', fn () => $hours->handle());
    $wefact = new \App\WeFact\StatusController($app['config'], $view, new \App\WeFact\DebtorCache($app['pdo']));
    $router->get('/wefact', fn () => $wefact->handle());
    $router->post('/wefact', fn () => $wefact->handle());
    $projects = new \App\Project\ProjectController($app['config'], $view, $app['pdo']);
    $router->get('/projecten', fn () => $projects->handle());
    $router->post('/projecten', fn () => $projects->handle());
    $preview = new \App\Invoice\PreviewController($app['config'], $view, $previewService);
    $router->get('/factuur-preview', fn () => $preview->handle());
    $router->post('/factuur-preview', fn () => $preview->handle());
    $invoiceRepo = new \App\Invoice\InvoiceRepository($app['pdo']);
    $fresh = function (array $hours) use ($app): array {
        $quota = new \App\Toggl\Quota($_SESSION['toggl_quota'] ?? []);
        try {
            $range = \App\Toggl\DateRange::fromInput($hours['from'], $hours['to'], new DateTimeZone($app['config']->get('APP_TIMEZONE', 'Europe/Amsterdam')));
            return (new \App\Toggl\TimeEntryReader(new \App\Toggl\TogglClient($app['config']->togglToken(), new \App\Toggl\CurlTransport(), $quota)))->read($range);
        } finally { $_SESSION['toggl_quota'] = $quota->state(); }
    };
    $invoiceService = new \App\Invoice\InvoiceService($previewService, $invoiceRepo, new \App\WeFact\WeFactClient($app['config']->wefactKey(), new \App\WeFact\CurlTransport()), $fresh, hash('sha256', $app['config']->wefactKey() . ':' . $app['config']->togglToken()));
    $syncTags = function(int $invoiceId,bool $retry) use ($app):array {
        $quota=new \App\Toggl\Quota($_SESSION['toggl_quota']??[]);
        // Share the same quota object with retry reads and writes.
        $client=new \App\Toggl\TogglClient($app['config']->togglToken(),new \App\Toggl\CurlTransport(),$quota);
        $refresh=function(array $period) use($app,$client):array {
            $range=\App\Toggl\DateRange::fromInput($period['from'],$period['to'],new DateTimeZone($app['config']->get('APP_TIMEZONE','Europe/Amsterdam')));
            return (new \App\Toggl\TimeEntryReader($client))->read($range);
        };
        try{return (new \App\Invoice\TagSyncService($app['pdo'],$client,hash('sha256',$app['config']->wefactKey().':'.$app['config']->togglToken()),$refresh))->sync($invoiceId,$retry);}
        finally{$_SESSION['toggl_quota']=$quota->state();}
    };
    $confirmation = new \App\Invoice\ConfirmationController($app['config'], $view, $invoiceService, $invoiceRepo, $syncTags);
    $router->get('/concept-bevestigen', fn () => $confirmation->handle());
    $router->post('/concept-bevestigen', fn () => $confirmation->handle());
    $history = new \App\Invoice\HistoryController($app['config'], $view, $invoiceRepo, $syncTags);
    $router->get('/facturen', fn () => $history->handle());
    $router->post('/facturen', fn () => $history->handle());
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Applicatie kon niet starten</h1><p>'.\App\View\View::escape($error instanceof \App\Database\AdministrationException?$error->getMessage():'Controleer de configuratie, Composer-installatie, PDO SQLite en schrijfrechten op storage.').'</p>';
}
