<?php
declare(strict_types=1);

use App\Config\Config;
use App\Database\Database;
use App\Database\Migrator;
use App\Http\Router;
use App\View\View;

require dirname(__DIR__) . '/vendor/autoload.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
check($migrator->migrate() === 12, 'Eerste migration ontbreekt.');
check($migrator->migrate() === 0, 'Migration is niet idempotent.');
foreach (['projects', 'invoiced_entries', 'api_cache', 'migrations', 'wefact_debtors', 'wefact_cache_state', 'toggl_projects', 'project_mappings', 'toggl_project_sync'] as $table) {
    $query = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
    $query->execute([$table]);
    check($query->fetchColumn() === $table, 'Tabel ontbreekt: ' . $table);
}
$insert = $pdo->prepare('INSERT INTO invoiced_entries (toggl_time_entry_id, toggl_project_id, wefact_invoice_id, duration_seconds, description, created_at) VALUES (?, ?, ?, ?, ?, ?)');
$values = [123, 456, 'invoice-test', 3600, 'Test', gmdate('c')];
$insert->execute($values);
$duplicateRejected = false;
try { $insert->execute($values); } catch (PDOException $error) { $duplicateRejected = true; }
check($duplicateRejected, 'Dubbele time-entry werd geaccepteerd.');
check(View::escape('<script>"&') === '&lt;script&gt;&quot;&amp;', 'HTML-output is niet escaped.');
$router = new Router();
$router->get('/', static function (): void { echo 'ok'; });
ob_start();
$router->dispatch('GET', '/?test=1');
check(ob_get_clean() === 'ok', 'Router verwerkt querystring niet.');
ob_start();
$router->dispatch('GET', '/onbekend');
ob_end_clean();
check(http_response_code() === 404, '404 ontbreekt.');
ob_start();
$router->dispatch('POST', '/');
ob_end_clean();
check(http_response_code() === 405, 'POST wordt niet geweigerd.');
echo "Fundament gecontroleerd: migrations, schema, unieke entries, HTML escaping en router.\n";
