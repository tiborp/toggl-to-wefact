<?php
declare(strict_types=1);

use App\Project\{HourlyRate, ProjectException, ProjectRepository, MappingRepository, ProjectService, ProjectSync, ProjectController};
use App\Toggl\{TogglClient, Quota, HttpTransport, HttpResponse, TogglException, TogglSession};
use App\Database\Migrator;
use App\WeFact\DebtorCache;
use App\Config\Config;
use App\View\View;
require dirname(__DIR__) . '/vendor/autoload.php';

function ensure(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function mappingFails(callable $call): void {
    try { $call(); } catch (ProjectException | TogglException) { return; }
    throw new RuntimeException('Verwachte validatiefout ontbreekt');
}
final class ProjectHttp implements HttpTransport {
    public array $paths = [];
    public function __construct(private array $responses) {}
    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse {
        $this->paths[] = $path;
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) { throw new RuntimeException('Onnodige extra API-request'); }
        return $response;
    }
}
function projectResponse(array $projects): HttpResponse { return new HttpResponse(200, json_encode($projects, JSON_THROW_ON_ERROR)); }
function project(int $id, string $name, bool $active = true): array { return ['id' => $id, 'name' => $name, 'workspace_id' => 100, 'active' => $active]; }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
ensure($migrator->migrate() === 13 && $migrator->migrate() === 0, 'Migration niet herhaalbaar');
$projects = new ProjectRepository($pdo);
$mappings = new MappingRepository($pdo);
$cache = new DebtorCache($pdo);
$tf = hash('sha256', 'stub-token'); $wf = hash('sha256', 'stub-key');
$cache->replace([
    ['wefact_debtor_id' => '42', 'debtor_number' => 'DB42', 'display_name' => '<script>Klant', 'company_name' => 'Test', 'first_name' => '', 'last_name' => '', 'city' => '', 'email' => 'test@example.invalid'],
    ['wefact_debtor_id' => '57', 'debtor_number' => 'DB57', 'display_name' => 'Bureau', 'company_name' => 'Bureau', 'first_name' => '', 'last_name' => '', 'city' => '', 'email' => 'test@example.invalid'],
], $wf);
$service = new ProjectService($projects, $mappings, $cache, $tf, $wf);
$initial = [project(1, '<script>Project'), project(2, 'Onderhoud'), project(3, 'Intern')];
$http = new ProjectHttp([projectResponse($initial)]);
$client = new TogglClient('stub-token', $http, new Quota());
ensure((new ProjectSync($client, $projects))->refresh($tf) === 3, 'Projecten niet opgeslagen');
$client->projectCatalog();
ensure($http->paths === ['/me/projects?include_archived=true'], 'Sync niet één bulkrequest of dubbele calls');
$rows = $projects->all($tf);
ensure(count($rows) === 3 && $rows[0]['toggl_workspace_id'] === 100 && $rows[0]['fetched_at'] !== '', 'Projectvelden ontbreken');

foreach (['85' => 8500, '85,00' => 8500, '85.00' => 8500, '97,50' => 9750, '1,5' => 150] as $input => $expected) {
    ensure(HourlyRate::parse((string) $input) === $expected, 'Geldparsing onjuist');
}
ensure(HourlyRate::parse('') === null && HourlyRate::format(9750) === '€ 97,50', 'Leeg/kommaformaat onjuist');
foreach (['0', '0,00', '-85', 'abc', '1.234', '1,234.56', '85,000', '1e2', '9999999999', 'NaN', [], null] as $invalid) { mappingFails(fn () => HourlyRate::parse($invalid)); }
$service->save('1', '42', '85,00');
$service->save('2', '42', '97.50');
ensure($mappings->find(1)['wefact_debtor_id'] === '42' && $mappings->find(2)['hourly_rate_cents'] === 9750, 'ID/rate mapping onjuist');
ensure($service->configuration(1) === ['wefact_debtor_id' => '42', 'hourly_rate_cents' => 8500], 'Lokale config lookup onjuist');
$created = $mappings->find(1)['created_at'];
$service->save('1', '57', '95');
ensure((int) $pdo->query('SELECT count(*) FROM project_mappings WHERE toggl_project_id=1')->fetchColumn() === 1 && $mappings->find(1)['created_at'] === $created, 'Conflicterende mapping/created_at veranderd');
$service->save('1', '42', '85');
$duplicateRejected = false;
try { $pdo->exec('INSERT INTO project_mappings (toggl_project_id, created_at, updated_at) VALUES (1, "test", "test")'); }
catch (PDOException) { $duplicateRejected = true; }
ensure($duplicateRejected, 'Unieke projectmapping-constraint ontbreekt');
$changed = [project(1, 'Hernoemd'), project(2, 'Onderhoud', false), project(4, 'Nieuw')];
$http = new ProjectHttp([projectResponse($changed)]);
(new ProjectSync(new TogglClient('stub-token', $http, new Quota()), $projects))->refresh($tf);
ensure($mappings->find(1)['wefact_debtor_id'] === '42' && $mappings->find(2)['hourly_rate_cents'] === 9750, 'Sync wijzigt mappings');
$indexed = []; foreach ($projects->all($tf) as $row) { $indexed[$row['toggl_project_id']] = $row; }
ensure($indexed[1]['name'] === 'Hernoemd' && isset($indexed[4]), 'Update/new project fout');
ensure(!$indexed[2]['active'] && !$indexed[3]['available'] && count($indexed) === 4, 'Inactief/verdwenen project verwijderd');
ensure($service->configuration(2) === null, 'Inactief project kan factureerbaar worden');
$service->save('3', '42', '80');
ensure($mappings->find(3) !== null && $service->configuration(3) === null, 'Ontbrekende projecten moeten configuratie behouden');
$service->save('4', '57', '');
$overview = $service->overview();
$statuses = []; foreach ($overview['projects'] as $row) { $statuses[$row['toggl_project_id']] = $row['status']; }
ensure($statuses[4] === 'Onvolledig' && $service->configuration(4) === null, 'Leeg tarief als gereed gemarkeerd');
$service->save('4', '', '');
ensure($mappings->find(4)['wefact_debtor_id'] === null, 'Ongekoppeld project fout');
foreach ([['999','42','85'], ['1','999','85'], ['1','42','0'], ['1','42','-1'], ['1',[],'85'], [[], '42', '85']] as [$projectId, $debtor, $rate]) {
    mappingFails(fn () => $service->save($projectId, $debtor, $rate));
}
$beforeProjects = $projects->all($tf); $beforeCache = $cache->read($wf);
$service->remove('4');
ensure($mappings->find(4) === null && $projects->all($tf) === $beforeProjects && $cache->read($wf) === $beforeCache, 'Verwijderen wist project/debiteur');
// WeFact full refresh may remove a debtor; no FK or cascade should destroy mappings.
$cache->replace([], $wf);
ensure($mappings->find(1)['wefact_debtor_id'] === '42' && $service->configuration(1) === null, 'Verdwenen debiteur vernietigt mapping of blijft gereed');
$overview = $service->overview();
foreach ($overview['projects'] as $row) { if ($row['toggl_project_id'] === 1) { ensure($row['status'] === 'Debiteur ontbreekt', 'Ontbrekende debiteur niet getoond'); } }
$service->save('1', '42', '86');
ensure($mappings->find(1)['hourly_rate_cents'] === 8600, 'Ongewijzigde ontbrekende link niet behouden');
mappingFails(fn () => $service->save('4', '42', '85'));
$service->remove('1');
ensure($mappings->find(1) === null && count($projects->all($tf)) === 4, 'Mapping verwijderen verwijdert project');
// Transaction rollback preserves both last project snapshot and mappings.
$before = $projects->all($tf);
$pdo->exec("CREATE TRIGGER fail_project_insert BEFORE INSERT ON toggl_projects BEGIN SELECT RAISE(ABORT, 'test'); END");
mappingFails(fn () => $projects->synchronize([project(5, 'Should fail')], $tf));
ensure($projects->all($tf) === $before && $mappings->find(2) !== null, 'Mislukte sync beschadigt vorige snapshot');
$pdo->exec('DROP TRIGGER fail_project_insert');
foreach ([new HttpResponse(402, 'stub-token'), projectResponse([['id'=>1,'name'=>'Invalid','active'=>true]]), projectResponse([project(1,'One'), project(1,'Duplicate')])] as $failure) {
    $http = new ProjectHttp([$failure]);
    mappingFails(fn () => (new ProjectSync(new TogglClient('stub-token', $http, new Quota()), $projects))->refresh($tf));
    ensure($projects->all($tf) === $before && count($http->paths) === 1, 'API fout veranderde cache/retry');
}
$quota = new Quota(['user' => ['blocked_until' => time() + 60]]);
$http = new ProjectHttp([]);
mappingFails(fn () => (new ProjectSync(new TogglClient('stub-token', $http, $quota), $projects))->refresh($tf));
ensure($http->paths === [], 'Quota niet hergebruikt');
$catalog = project(1,'Incomplete'); $catalog['total_count'] = 2;
mappingFails(fn () => (new ProjectSync(new TogglClient('stub-token', new ProjectHttp([projectResponse([$catalog])]), new Quota()), $projects))->refresh($tf));

// Actual controller/form tests use only stub Toggl and local SQLite; no WeFact client.
$temp = sys_get_temp_dir() . '/project-config-' . bin2hex(random_bytes(8));
mkdir($temp); file_put_contents($temp . '/.env', "TOGGL_API_TOKEN=stub-token\nWEFACT_API_KEY=stub-key\n");
$config = new Config($temp);
$cache->replace([['wefact_debtor_id'=>'42','debtor_number'=>'DB42','display_name'=>'<script>Klant','company_name'=>'Test','first_name'=>'','last_name'=>'','city'=>'','email'=>'']], $wf);
$http = new ProjectHttp([projectResponse([project(1, '<script>Renamed'), project(4,'Nieuw')])]);
$controller = new ProjectController($config, new View(dirname(__DIR__) . '/templates'), $pdo, $http);
try {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = [];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    $sessionId = session_id();
    ensure($http->paths === [] && str_contains($html,'Hernoemd'), 'GET deed API-calls of lokale projecten ontbreken');
    ensure(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;Klant'), 'Debiteurnaam niet escaped');
    $storedMappings=$mappings->all();$storedProjects=$projects->all($tf);
    $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
    ensure($xp->query('//form[@id="project-2"]')->length===0&&$xp->query('//form[@id="project-1"]')->length===1,'Projectpagina toont standaard gearchiveerde projecten');
    $_GET=['show_archived'=>'1','q'=>'Onderhoud'];ob_start();$controller->handle();$archivedHtml=ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML($archivedHtml);$xp=new DOMXPath($dom);
    ensure($xp->query('//form[@id="project-2"]')->length===1&&str_contains($archivedHtml,'Gearchiveerd')&&str_contains($archivedHtml,'97,50'),'Archiefweergave verliest project/tarief');
    ensure($xp->query('//input[@name="show_archived" and @checked]')->length===1&&str_contains($archivedHtml,'show_archived=1'),'Archiefkeuze verdwijnt uit formulieren');
    ensure($http->paths===[]&&$mappings->all()===$storedMappings&&$projects->all($tf)===$storedProjects,'Archieffilter wijzigt gegevens of doet API-calls');
    ensure(str_contains($archivedHtml,'Na archiveren in Toggl'),'Catalogusrefresh niet uitgelegd');
    ensure($xp->query('//form[@method="get" and @action="/projecten#project-list" and @data-project-catalog-filter]//input[@data-project-archive-toggle]')->length===1,'Automatisch archieffilter niet op lokaal GET-formulier');
    ensure($xp->query('//*[@id="project-list"]')->length===1&&str_contains($archivedHtml,'/assets/daily.js'),'Anchor of progressive enhancement ontbreekt');
    $_GET=['q'=>'Onderhoud'];ob_start();$controller->handle();$uncheckedHtml=ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML($uncheckedHtml);$xp=new DOMXPath($dom);
    ensure($xp->query('//form[@id="project-2"]')->length===0&&$xp->query('//input[@name="q" and @value="Onderhoud"]')->length===1&&$http->paths===[],'Uitvinken moet lokaal archief verbergen en zoekterm behouden');


    preg_match('/name="csrf" value="([^"]+)"/', $html, $match); $csrf = $match[1];
    $_GET = ['q' => 'HERN'];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    ensure($http->paths === [] && str_contains($html,'Hernoemd') && !str_contains($html,'Onderhoud'), 'Zoekfilter niet lokaal');
    $_GET = []; $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['csrf'=>'invalid','action'=>'save','project_id'=>'1','debtor_id'=>'42','rate'=>'85'];
    ob_start(); $controller->handle(); ob_end_clean();
    ensure(http_response_code() === 403 && $mappings->find(1) === null && $http->paths === [], 'CSRF-save geaccepteerd');
    $_POST = ['csrf'=>$csrf,'action'=>'save','project_id'=>'1','debtor_id'=>'42','rate'=>'85'];
    ob_start(); $controller->handle(); $output = ob_get_clean();
    ensure(http_response_code() === 303 && $output === '' && $http->paths === [] && $mappings->find(1)['hourly_rate_cents'] === 8500, 'Lokale save/PRG mislukt');
    $_POST = ['csrf'=>$csrf,'action'=>'sync'];
    ob_start(); $controller->handle(); ob_end_clean();
    ensure(http_response_code() === 303 && count($http->paths) === 1 && $mappings->find(1)['hourly_rate_cents'] === 8500, 'Sync niet bulk of mapping veranderd');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    ensure(count($http->paths) === 1 && str_contains($html,'&lt;script&gt;Renamed') && !str_contains($html,'<script>'), 'GET refresh deed API-call/escaping onjuist');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['csrf'=>$csrf,'action'=>'remove','project_id'=>'1'];
    ob_start(); $controller->handle(); ob_end_clean();
    ensure(http_response_code() === 303 && $mappings->find(1) === null && count($http->paths) === 1 && $cache->read($wf)['metadata']['debtor_count'] === 1, 'Verwijderactie extern of debtor verwijderd');
    $service->save('1','42','85');
    $otherKey = new ProjectService($projects,$mappings,$cache,$tf,hash('sha256','other-key'));
    ensure($otherKey->configuration(1) === null, 'Koppeling van andere administratie als geldig beschouwd');
    mappingFails(fn () => $otherKey->save('1','42','85'));
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (isset($sessionId)) { $file = dirname(__DIR__) . '/storage/sess_' . $sessionId; if (is_file($file)) { unlink($file); } }
    unlink($temp . '/.env');rmdir($temp);
}
echo "Projectmapping-tests geslaagd: bulk-sync, behoud/rename, IDs, integer-tarieven, constraints, cacheverlies, lokale lookup/filter, CSRF, PRG en escaping.\n";
