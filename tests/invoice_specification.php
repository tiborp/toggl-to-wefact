<?php
declare(strict_types=1);
namespace App\Toggl { function curl_exec($handle) { $GLOBALS['spec_api_calls']++; throw new \RuntimeException('Unexpected API request'); } }
namespace App\WeFact { function curl_exec($handle) { $GLOBALS['spec_api_calls']++; throw new \RuntimeException('Unexpected API request'); } }
namespace {
use App\Invoice\{Specification, SpecificationController};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
require dirname(__DIR__).'/vendor/autoload.php';
function specCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$GLOBALS['spec_api_calls'] = 0;
$invoice = ['id'=>7, 'toggl_project_id'=>8001, 'project_name'=>'Example <Project>', 'debtor_name'=>'Example Client', 'wefact_invoice_code'=>'TEST-700', 'wefact_invoice_id'=>'EX-REMOTE', 'wefact_status'=>2,
    'period_from'=>'2026-09-01', 'period_to'=>'2026-09-30', 'amount_excl_cents'=>2500, 'duration_seconds'=>360,
    'billed_duration_seconds'=>900, 'snapshot_json'=>'{"entry_ids":[9001]}'];
$entry = ['toggl_time_entry_id'=>9001, 'toggl_project_id'=>8001, 'duration_seconds'=>360, 'description'=>'Example work', 'start'=>'2026-09-02T10:00:00Z', 'released_at'=>null];
$one = Specification::build($invoice, [$entry]);
specCheck($one['available'] && count($one['groups']) === 1 && $one['groups'][0]['duration_seconds'] === 360, 'Single description missing');
specCheck($one['invoice']['wefact_status'] === 2, 'Non-draft history must remain accessible');
specCheck($one['actual_seconds'] === 360 && $one['invoice']['billed_duration_seconds'] === 900, 'Actual/billed distinction lost');
$entries = [];
foreach ([' Example work ', 'eXAMPLE WORK', '', '   ', 'Other work', 'Werkzaamheden'] as $n=>$description) {
    $entries[] = array_replace($entry, ['toggl_time_entry_id'=>9001+$n, 'description'=>$description, 'duration_seconds'=>61+$n]);
}
$multi = array_replace($invoice, ['duration_seconds'=>381, 'snapshot_json'=>json_encode(['entry_ids'=>array_column($entries, 'toggl_time_entry_id')])]);
$result = Specification::build($multi, $entries);
specCheck($result['available'] && count($result['groups']) === 4, 'Grouping must trim and ignore case without fuzzy merging');
$groups = array_column($result['groups'], null, 'description');
specCheck($groups['Example work']['duration_seconds'] === 123 && count($groups['Example work']['entries']) === 2, 'Case/whitespace grouping failed');
specCheck($groups['Werkzaamheden']['duration_seconds'] === 66, 'Explicit fallback description must retain its distinct normalized key');
$blank = array_values(array_filter($result['groups'], fn($g)=>count($g['entries'])===2 && $g['description']==='Werkzaamheden'));
specCheck(count($blank) === 1 && $blank[0]['duration_seconds'] === 127, 'Empty descriptions must use Werkzaamheden');
specCheck($result['actual_seconds'] === 381 && array_sum(array_column($result['groups'], 'duration_seconds')) === 381, 'Exact seconds changed');
foreach ([[], array_slice($entries, 1), [...$entries, $entries[0]]] as $bad) {
    specCheck(!Specification::build($multi, $bad)['available'], 'Incomplete/duplicate entry set accepted');
}
foreach (['', '{}', '{broken', '{"entry_ids":[null]}'] as $snapshot) {
    specCheck(!Specification::build(array_replace($invoice, ['snapshot_json'=>$snapshot]), [$entry])['available'], 'Missing historical snapshot accepted');
}
specCheck(!Specification::build(array_replace($invoice, ['duration_seconds'=>361]), [$entry])['available'], 'Mismatched historical total accepted');
specCheck(!Specification::build($invoice, [array_replace($entry, ['duration_seconds'=>-1])])['available'], 'Negative duration accepted');
specCheck(!Specification::build($invoice, [array_replace($entry, ['toggl_project_id'=>8002])])['available'], 'Wrong project accepted');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new Migrator($pdo, dirname(__DIR__).'/database/migrations'))->migrate();
$pdo->prepare("INSERT INTO invoice_actions(token_hash,action_fingerprint,owner_hash,dataset_id,toggl_project_id,source_fingerprint,preview_hash,snapshot_json,state,created_at,updated_at) VALUES('fixture','fixture','fixture','fixture',8001,'fixture','fixture',?,'succeeded','2026-09-30','2026-09-30')")->execute([$invoice['snapshot_json']]);
$pdo->exec("INSERT INTO invoices(id,token_hash,toggl_project_id,project_name,wefact_debtor_id,debtor_name,wefact_source_fingerprint,wefact_invoice_id,wefact_invoice_code,wefact_status,invoice_date,period_from,period_to,hourly_rate_cents,duration_seconds,billed_duration_seconds,amount_excl_cents,line_mode,lines_json,created_at) VALUES(7,'fixture',8001,'Example <Project>','EX-CLIENT','Example Client','fixture','EX-REMOTE','TEST-700',0,'2026-09-30','2026-09-01','2026-09-30',10000,360,900,2500,'summary','[]','2026-09-30')");
$pdo->exec("INSERT INTO invoiced_entries(invoice_id,toggl_time_entry_id,toggl_project_id,duration_seconds,description,start,created_at) VALUES(7,9001,8001,360,'<script>alert(1)</script>','2026-09-02T10:00:00Z','2026-09-30')");
$reader = new Specification($pdo);
specCheck($reader->find(7)['available'] && $reader->find(99) === null, 'Repository lookup failed');
// Closed registrations retain their original entries, even after release for rebilling.
$pdo->exec("UPDATE invoices SET lifecycle='manual_deleted_test',closed_at='2026-10-01',closure_reason='Example test closed' WHERE id=7");
$pdo->exec("UPDATE invoiced_entries SET released_at='2026-10-01' WHERE invoice_id=7");
specCheck($reader->find(7)['available'], 'Closed/released invoice specification lost');
$before = $pdo->query('SELECT * FROM invoiced_entries')->fetchAll();
$pdo->exec('PRAGMA query_only=ON');
$controller = new SpecificationController(new Config('/nonexistent-example-config'), new View(dirname(__DIR__).'/templates'), $reader);
function renderSpec(SpecificationController $controller, mixed $id): array {
    $_GET = ['invoice_id'=>$id]; http_response_code(200); ob_start(); $controller->handle(); return [http_response_code(), ob_get_clean()];
}
[$status, $html] = renderSpec($controller, '7');
specCheck($status === 200 && str_contains($html, 'Totaal geregistreerd') && str_contains($html, '0:06') && str_contains($html, '0:15'), 'GET specification failed');
specCheck(str_contains($html, 'Example &lt;Project&gt;') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>alert'), 'HTML escaping failed');
specCheck(!str_contains($html, 'EX-REMOTE') && !str_contains($html, '>9001<'), 'Technical IDs unexpectedly displayed');
foreach (['0', '-1', '1.0', '1e1', '7 OR 1=1', '999999999999999999999999', ['7'], null] as $invalid) {
    specCheck(renderSpec($controller, $invalid)[0] === 400, 'Invalid local ID accepted');
}
specCheck(renderSpec($controller, '99')[0] === 404, 'Unknown invoice not 404');
$pdo->exec('PRAGMA query_only=OFF');
$pdo->exec('DELETE FROM invoiced_entries WHERE invoice_id=7');
$pdo->exec('PRAGMA query_only=ON');
[$status, $missing] = renderSpec($controller, '7');
specCheck($status === 200 && str_contains($missing, 'onvoldoende betrouwbare historische gegevens') && !str_contains($missing, 'Totaal geregistreerd'), 'Incomplete history must not be reconstructed');
specCheck($GLOBALS['spec_api_calls'] === 0, 'GET invoked an external API');
$pdo->exec('PRAGMA query_only=OFF');
// Restore only the test row and check that GET did not alter any invoice/entry state.
$pdo->prepare('INSERT INTO invoiced_entries('.implode(',', array_keys($before[0])).') VALUES('.implode(',', array_fill(0, count($before[0]), '?')).')')->execute(array_values($before[0]));
specCheck($pdo->query('SELECT * FROM invoiced_entries')->fetchAll() === $before, 'Read-only view changed entries');
specCheck((int)$pdo->query('SELECT COUNT(*) FROM invoice_entry_reservations')->fetchColumn() === 0, 'View created reservations');
// Public wiring remains a GET route, with no creation or external transport dependency in this flow.
$wiring = file_get_contents(dirname(__DIR__).'/public/index.php');
specCheck(str_contains($wiring, "\$router->get('/facturen/urenspecificatie'"), 'GET route missing');
$source = file_get_contents(dirname(__DIR__).'/src/Invoice/SpecificationController.php').file_get_contents(dirname(__DIR__).'/src/Invoice/Specification.php');
specCheck(!str_contains($source, 'TogglClient') && !str_contains($source, 'WeFactClient'), 'Historical flow depends on API clients');
$history = ['invoices'=>[$invoice+['invoice_date'=>'2026-09-30']], 'closed_invoices'=>[], 'uncertain'=>[], 'failed'=>[]];
$error=null; $data=[]; $message=null;
ob_start(); require dirname(__DIR__).'/templates/invoices.php'; $historyHtml=ob_get_clean();
specCheck(str_contains($historyHtml, '/facturen/urenspecificatie?invoice_id=7'), 'History must link with local ID');
$history = ['invoices'=>[], 'closed_invoices'=>[$invoice+['invoice_date'=>'2026-09-30', 'closed_at'=>'2026-10-01', 'closure_reason'=>'Example test closed']], 'uncertain'=>[], 'failed'=>[]];
ob_start(); require dirname(__DIR__).'/templates/invoices.php'; $closedHtml=ob_get_clean();
specCheck(str_contains($closedHtml, '/facturen/urenspecificatie?invoice_id=7'), 'Closed invoice must retain its specification link');
$long = Specification::build(array_replace($invoice, ['duration_seconds'=>89665, 'billed_duration_seconds'=>90000]), [array_replace($entry, ['duration_seconds'=>89665])]);
specCheck($long['actual_seconds'] === 89665 && $long['groups'][0]['duration_seconds'] === 89665 && $long['invoice']['billed_duration_seconds'] === 90000, 'Long durations must preserve all seconds');
echo "Invoice specification tests passed.\n";
}
