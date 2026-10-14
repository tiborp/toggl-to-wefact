<?php
declare(strict_types=1);

use App\Toggl\{DateRange, HttpResponse, HttpTransport, Quota, TogglClient, TogglException, TimeEntryReader, TimeEntryOverview, TimeEntryStatus};
use App\View\View;
require dirname(__DIR__) . '/vendor/autoload.php';

function expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
final class TimeHttp implements HttpTransport {
    public array $paths = [];
    public function __construct(private array $responses) {}
    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse {
        $this->paths[] = $path;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        if (!$response instanceof HttpResponse) { throw new RuntimeException('Onverwachte extra request'); }
        return $response;
    }
}
function batch(array $entries, array $headers = []): HttpResponse {
    return new HttpResponse(200, json_encode($entries, JSON_THROW_ON_ERROR), $headers);
}
function entry(int $id, ?int $project = 10, int $duration = 3600, array $tags = [], ?string $stop = '2026-09-01T12:00:00Z', string $start = '2026-09-01T10:00:00Z'): array {
    return ['id' => $id, 'project_id' => $project, 'workspace_id' => 1, 'description' => '<script>"&', 'start' => $start, 'stop' => $stop, 'duration' => $duration, 'tags' => $tags];
}
$tz = new DateTimeZone('Europe/Amsterdam');
$utc = new DateTimeZone('UTC');
foreach ([
    ['2026-10-14T12:00:00+02:00','2026-07-14'],
    ['2026-05-31T12:00:00+02:00','2026-02-28'],
    ['2024-05-31T12:00:00+02:00','2024-02-29'],
    ['2026-01-31T12:00:00+01:00','2025-10-31'],
    ['2026-10-13T22:30:00Z','2026-07-14'],
] as [$now,$expected]) {
    $minimum=DateRange::earliestStart($tz,new DateTimeImmutable($now));
    expect($minimum->format('Y-m-d')===$expected,'Kalendergrens/timezone onjuist');
    DateRange::fromInput($expected,$expected,$tz)->assertAvailableFrom($minimum);
    try {
        $previous=$minimum->modify('-1 day')->format('Y-m-d');
        DateRange::fromInput($previous,$expected,$tz)->assertAvailableFrom($minimum);
        throw new RuntimeException('Dag vóór kalendergrens toegestaan');
    } catch(TogglException $e) {
        expect(str_contains($e->getMessage(),$minimum->format('d-m-Y')),'Grens ontbreekt in foutmelding');
    }
}

$range = DateRange::fromInput('2026-08-17', '2026-10-04', $tz);
expect($range->start->format('Y-m-d H:i:s') === '2026-08-17 00:00:00', 'Van ontbreekt');
expect($range->endExclusive->format('Y-m-d H:i:s') === '2026-10-05 00:00:00', 'Tot is niet inclusief');
foreach ([['2026-03-29', 23], ['2026-10-25', 25]] as [$day, $hours]) {
    $dst = DateRange::fromInput($day, $day, $tz);
    expect($dst->endExclusive->getTimestamp() - $dst->start->getTimestamp() === $hours * 3600, 'DST datumgrens onjuist');
}
foreach ([[null, '2026-09-01'], ['', ''], [[], '2026-09-01'], ['2026-02-30', '2026-03-01'], ['2026-9-1', '2026-09-02'], ['0000-01-01', '2026-09-02'], ['2026-09-03', '2026-09-02']] as [$from, $to]) {
    $http = new TimeHttp([]);
    try {
        $bad = DateRange::fromInput($from, $to, $tz);
        (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($bad);
        throw new RuntimeException('Ongeldige range geaccepteerd');
    } catch (TogglException) {}
    expect($http->paths === [], 'Ongeldige range veroorzaakte request');
}
$range = DateRange::fromInput('2026-09-01', '2026-09-01', $tz);
$fixtures = [
    entry(1, 10, 8101), entry(2, 10, 5402, ['meeting']),
    entry(3, null, 45), entry(4, 20, -1, [], null),
    entry(5, 20, 500, ['invoiced']), entry(6, 20, 500, ['Billed by factuursturen.nl']),
    entry(7, 20, 500, ['INVOICED']), entry(8, 20, 500, ['bIlLeD bY FaCtUuRsTuReN.nL']),
    entry(9, 20, 60, ['uninvoiced']), entry(10, 20, 300, [], null),
];
$http = new TimeHttp([batch($fixtures, ['X-Toggl-Quota-Remaining' => '29', 'X-Toggl-Quota-Resets-In' => '120'])]);
$quota = new Quota();
$client = new TogglClient('stub-secret', $http, $quota);
$result = (new TimeEntryReader($client))->read($range);
expect($result['complete'] && count($result['entries']) === 10 && count($http->paths) === 1, 'Opvragen moet één request zijn');
parse_str(parse_url($http->paths[0], PHP_URL_QUERY), $query);
expect(parse_url($http->paths[0], PHP_URL_PATH) === '/me/time_entries', 'Verkeerd endpoint');
expect($query === ['start_date' => '2026-08-31T22:00:00Z', 'end_date' => '2026-09-01T22:00:00Z'], 'UTC/datumparameters onjuist');
$client->timeEntries($range->start, $range->endExclusive);
expect(count($http->paths) === 1, 'Dubbele time-entry request');
expect($quota->state()['user']['remaining'] === 29, 'Quota niet hergebruikt');

$projects = [['id' => 10, 'name' => '<script>Project'], ['id' => 20, 'name' => 'Other']];
$overview = new TimeEntryOverview();
$all = $overview->build($result['entries'], $projects, false, $tz);
$filtered = $overview->build($result['entries'], $projects, true, $tz);
expect(count($all['groups']) === 3, 'Groepering/projectloos onjuist');
expect($all['seconds'] === 8101 + 5402 + 45 + 60 && $filtered['seconds'] === $all['seconds'], 'Gefactureerd/running telt mee');
expect(array_sum(array_map(fn ($g) => count($g['entries']), $all['groups'])) === 10, 'Alle entries niet zichtbaar');
expect(array_sum(array_map(fn ($g) => count($g['entries']), $filtered['groups'])) === 6, 'Filter onjuist');
$none = array_values(array_filter($all['groups'], fn ($g) => $g['project_id'] === null))[0];
expect($none['name'] === 'Geen project' && $none['seconds'] === 45, 'Geen project ontbreekt');
$group10 = array_values(array_filter($all['groups'], fn ($g) => $g['project_id'] === 10))[0];
expect($group10['seconds'] === 13503 && TimeEntryOverview::duration(13503) === '3:45:03', 'Exacte seconden verloren');
expect(TimeEntryOverview::duration(90000) === '25:00', 'Uren mogen niet na 24 uur omslaan');
foreach (['invoiced', 'INVOICED', 'Billed by factuursturen.nl', 'bIlLeD bY FaCtUuRsTuReN.nL'] as $tag) {
    expect(TimeEntryStatus::isInvoiced(entry(1, 10, 1, [$tag])), 'Facturatietag niet herkend');
    expect(!TimeEntryStatus::isBillable(entry(1, 10, 1, [$tag])), 'Gefactureerd selecteerbaar');
}
foreach (['meeting', 'uninvoiced', 'invoiced-copy', ' Billed by factuursturen.nl', 'Billed by factuursturen.nl extra'] as $tag) {
    expect(!TimeEntryStatus::isInvoiced(entry(1, 10, 1, [$tag])), 'Geen volledige tagmatch');
}
expect(TimeEntryStatus::invoiceSources(entry(1, 10, 1, ['invoiced', 'Billed by factuursturen.nl'])) === ['WeFact', 'Factuursturen.nl'], 'Bronlabels onjuist');
expect(TimeEntryStatus::isRunning(entry(1, 10, -100, [], null)), 'Running niet herkend');
expect(!TimeEntryStatus::isBillable(entry(1, 10, 50, [], null)), 'Entry zonder stop selecteerbaar');
$unknown = $overview->build([entry(1, 99)], [], false, $tz);
expect(str_contains($unknown['groups'][0]['name'], '99'), 'Onbekend project-ID verdwenen');

// Include whole last day, exclude exact next midnight; select on start, not overlap.
$edges = [entry(20, 10, 60, [], '2026-09-01T22:59:59Z', '2026-09-01T21:59:59Z'), entry(21, 10, 60, [], '2026-09-01T23:00:00Z', '2026-09-01T22:00:00Z'), entry(22, 10, 60, [], '2026-08-31T22:10:00Z', '2026-08-31T21:59:59Z')];
$http = new TimeHttp([batch($edges)]);
$edgeResult = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect(array_column($edgeResult['entries'], 'id') === [20], 'Datumgrenzen niet exact');

// 1000-item cap: split once, deduplicate overlapping parent and child results.
$range = DateRange::fromInput('2026-09-01', '2026-09-02', $utc);
$left = []; $right = [];
for ($i = 1; $i <= 500; $i++) { $left[] = entry($i); }
for ($i = 501; $i <= 1001; $i++) { $right[] = entry($i, 10, 60, [], '2026-09-02T12:00:00Z', '2026-09-02T10:00:00Z'); }
$parent = array_slice(array_merge($left, $right), 0, 1000);
$http = new TimeHttp([batch($parent), batch($left), batch($right)]);
$split = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect($split['complete'] && count($split['entries']) === 1001 && count($http->paths) === 3, 'Splitsen/deduplicatie onjuist');
expect(count(array_unique($http->paths)) === 3, 'Dubbele subrange requests');
$http = new TimeHttp([batch($parent, ['X-Toggl-Quota-Remaining' => '0', 'X-Toggl-Quota-Resets-In' => '120'])]);
$limited = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect(!$limited['complete'] && count($limited['entries']) === 1000 && count($http->paths) === 1, 'Quota niet veilig gestopt');
$http = new TimeHttp(array_fill(0, 8, batch($parent)));
$capped = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect(!$capped['complete'] && count($http->paths) === 8 && str_contains($capped['warning'], 'acht'), 'Requestbudget niet begrensd');
foreach ([401, 403, 402, 429, 503] as $code) {
    $http = new TimeHttp([new HttpResponse($code, 'stub-secret')]);
    $failure = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
    expect(!$failure['complete'] && count($http->paths) === 1 && !str_contains($failure['warning'], 'stub-secret'), 'API-fout/retry/credentiallek');
}
$http = new TimeHttp([new TogglException('Toggl is niet bereikbaar.')]);
$failure = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect(!$failure['complete'] && str_contains($failure['warning'], 'niet bereikbaar'), 'Netwerkfout niet afgehandeld');
foreach ([new HttpResponse(200, '{}'), batch([entry(1) + ['unused' => 'value']])] as $index => $response) {
    $http = new TimeHttp([$response]);
    $parsed = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
    expect($parsed['complete'] === ($index === 1), 'Response shape check');
}
foreach (['start', 'duration', 'tags', 'workspace_id'] as $field) {
    $bad = entry(1); $bad[$field] = $field === 'tags' ? [123] : ['invalid'];
    $http = new TimeHttp([batch([$bad])]);
    $parsed = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
    expect(!$parsed['complete'], 'Ongeldige entry geaccepteerd: ' . $field);
}
$legacy = entry(1); unset($legacy['project_id']); $legacy['pid'] = 10; $legacy['description'] = null; $legacy['tags'] = null;
$http = new TimeHttp([batch([$legacy])]);
$parsed = (new TimeEntryReader(new TogglClient('stub-secret', $http, new Quota())))->read($range);
expect($parsed['entries'][0]['project_id'] === 10 && $parsed['entries'][0]['tags'] === [] && $parsed['entries'][0]['description'] === '', 'Legacy/nullvelden onjuist');


// Empty batches terminate normally; completeness belongs to the fetch, never filtering.
$emptyHttp = new TimeHttp([batch([])]);
$emptyFetch = (new TimeEntryReader(new TogglClient('stub-secret', $emptyHttp, new Quota())))->read($range);
expect($emptyFetch['complete'] && $emptyFetch['warning'] === null && $emptyFetch['entries'] === [] && count($emptyHttp->paths) === 1, 'Lege batch is geen fout');
$base = ['only_uninvoiced'=>true,'project_filter'=>''];
expect(str_contains(\App\Toggl\ProjectChoice::emptyMessage($emptyFetch+$base),'Geen uren gevonden'), 'Geldige lege fetch verkeerd gemeld');
$invoicedParent = array_map(static function($e){$e['tags']=['invoiced'];return $e;},$parent);
$allBilled = (new TimeEntryReader(new TogglClient('stub-secret', new TimeHttp([batch([$invoicedParent[0]])]), new Quota())))->read($range);
expect($allBilled['complete'] && $overview->build($allBilled['entries'], [], true, $tz)['groups'] === [], 'Volledig gefactureerd is geen incomplete fetch');
expect(str_contains(\App\Toggl\ProjectChoice::emptyMessage($allBilled+$base),'al gefactureerd'), 'Facturatiefilter lege state fout');
$filterHours=$allBilled+['only_uninvoiced'=>true,'project_filter'=>'999'];
expect(str_contains(\App\Toggl\ProjectChoice::emptyMessage($filterHours),'gekozen filters'), 'Projectfilter lege state fout');
$partialBilled = (new TimeEntryReader(new TogglClient('stub-secret', new TimeHttp([batch($invoicedParent),new HttpResponse(200,'{}')]), new Quota())))->read($range);
expect(!$partialBilled['complete'] && count($partialBilled['entries'])===1000 && $overview->build($partialBilled['entries'],[],true,$tz)['groups']===[], 'Leeg factureerbaar resultaat verbergt gedeeltelijke fetch');
expect(str_contains(\App\Toggl\ProjectChoice::emptyMessage($partialBilled+$base),'onvolledige'),'Gedeeltelijke dataset mag niet volledig gefactureerd claimen');
$emptyChild = (new TimeEntryReader(new TogglClient('stub-secret', new TimeHttp([batch($parent),batch([]),batch($right)]), new Quota())))->read($range);
expect($emptyChild['complete'] && $emptyChild['warning']===null, 'Lege vervolgbatch is geen malformed response');
$rejected = (new TimeEntryReader(new TogglClient('stub-secret', new TimeHttp([new HttpResponse(400,json_encode('start_date must not be earlier than 2026-07-14'))]), new Quota())))->read(DateRange::fromInput('2026-01-01','2026-10-14',$tz));
expect(!$rejected['complete'] && $rejected['entries']===[] && str_contains($rejected['warning'],'14-07-2026') && !str_contains($rejected['warning'],'onverwachte'),'Concrete jaaropvraagafwijzing niet herkenbaar');
expect(!str_contains(\App\Toggl\ProjectChoice::emptyMessage($rejected+$base),'Geen uren gevonden'),'API-fout ten onrechte lege periode');
foreach (['{}','null','broken','[{"id":1}]'] as $body) {
    $badFetch=(new TimeEntryReader(new TogglClient('stub-secret',new TimeHttp([new HttpResponse(200,$body)]),new Quota())))->read($range);
    expect(!$badFetch['complete'] && $badFetch['warning']!==null,'Malformed response verliest waarschuwing');
}
// Render empty complete/incomplete datasets: a real fetch warning must remain visible.
foreach ([$emptyFetch,$allBilled,$partialBilled,$rejected] as $fetch) {
    $renderHours=$fetch+$base+['from'=>'2026-09-01','to'=>'2026-09-02','loaded_at'=>'2026-09-02T12:00:00Z','project_warning'=>null];
    ob_start();
    (new View(dirname(__DIR__).'/templates'))->page('Test','Uren','','/uren',[
        'hours'=>$renderHours,'overview'=>['groups'=>[],'seconds'=>0],'quotas'=>[],'csrf'=>'test','error'=>null,
        'from'=>'2026-09-01','to'=>'2026-09-02','onlyUninvoiced'=>true,
        'emptyMessage'=>\App\Toggl\ProjectChoice::emptyMessage($renderHours),
    ]);
    $rendered=ob_get_clean();
    expect(str_contains($rendered,'dit is geen volledige facturatieselectie')===!$fetch['complete'],'Waarschuwing afhankelijk van lege eindresultaten');
}

$hours = ['from' => '2026-09-01', 'to' => '2026-09-01', 'complete' => false, 'warning' => 'Resultaten onvolledig', 'loaded_at' => '2026-09-01T10:00:00Z', 'project_warning' => null];
ob_start();
(new View(dirname(__DIR__) . '/templates'))->page('Test', 'Uren', '', '/uren', ['hours' => $hours, 'overview' => $all, 'quotas' => [], 'csrf' => 'test', 'error' => null, 'from' => '2026-09-01', 'to' => '2026-09-01', 'onlyUninvoiced' => true]);
$html = ob_get_clean();
expect(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'HTML niet escaped');
expect(substr_count($html, 'name="selected[') === 4, 'Running/gefactureerd heeft actieve checkbox');
expect(str_contains($html, 'Gefactureerd · Factuursturen.nl') && str_contains($html, 'Gefactureerd · WeFact'), 'Bronlabels ontbreken');
expect(str_contains($html, 'Resultaten onvolledig'), 'Onvolledig resultaat niet gemarkeerd');

// Exercise actual POST/redirect/GET and local filtering using the same HTTP stub.
$temp = sys_get_temp_dir() . '/toggl-hours-controller-' . bin2hex(random_bytes(8));
mkdir($temp);
file_put_contents($temp . '/.env', "TOGGL_API_TOKEN=stub-secret\nAPP_TIMEZONE=Europe/Amsterdam\n");
$config = new \App\Config\Config($temp);
$http = new TimeHttp([batch($fixtures)]);
$controller = new \App\Toggl\TimeEntryController($config, new View(dirname(__DIR__) . '/templates'), $http);
try {
    \App\Toggl\TogglSession::open($config);
    $csrf = $_SESSION['csrf'];
    $_SESSION['toggl_projects'] = [10 => $projects[0], 20 => $projects[1]];
    $sessionId = session_id();
    session_write_close();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => $csrf, 'from' => '2026-09-01', 'to' => '2026-09-01', 'only_uninvoiced' => '1', 'action' => 'fetch'];
    ob_start(); $controller->handle(); $postOutput = ob_get_clean();
    expect($postOutput === '' && http_response_code() === 303 && count($http->paths) === 1, 'POST/redirect of projecthergebruik mislukt');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    ob_start(); $controller->handle(); $getOutput = ob_get_clean();
    expect(count($http->paths) === 1 && !str_contains($getOutput, 'Gefactureerd ·'), 'GET herhaalt API of standaardfilter onjuist');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => $csrf, 'from' => '2026-09-01', 'to' => '2026-09-01', 'action' => 'filter'];
    ob_start(); $controller->handle(); ob_end_clean();
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    ob_start(); $controller->handle(); $filterOutput = ob_get_clean();
    expect(count($http->paths) === 1 && str_contains($filterOutput, 'Gefactureerd · Factuursturen.nl'), 'Filter doet API-call of facturatiebronnen ontbreken');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf' => $csrf, 'from' => '2026-02-30', 'to' => '2026-03-01', 'action' => 'fetch'];
    ob_start(); $controller->handle(); ob_end_clean();
    expect(http_response_code() === 400 && count($http->paths) === 1, 'Ongeldige controllerinput doet API-call');
    $_POST = ['csrf' => 'invalid', 'from' => '2026-09-01', 'to' => '2026-09-01', 'action' => 'fetch'];
    ob_start(); $controller->handle(); ob_end_clean();
    expect(http_response_code() === 403 && count($http->paths) === 1, 'CSRF niet beschermd');

    // Handcrafted requests are rejected before transport; the exact boundary is allowed.
    $minimum=DateRange::earliestStart($tz);
    $boundaryHttp=new TimeHttp([batch([])]);
    $boundaryController=new \App\Toggl\TimeEntryController($config,new View(dirname(__DIR__).'/templates'),$boundaryHttp);
    $_POST=['csrf'=>$csrf,'from'=>$minimum->modify('-1 day')->format('Y-m-d'),'to'=>$minimum->format('Y-m-d'),'action'=>'fetch'];
    ob_start();$boundaryController->handle();$boundaryError=ob_get_clean();
    expect(http_response_code()===400&&$boundaryHttp->paths===[]&&str_contains($boundaryError,'drie kalendermaanden'),'Oude handmatige request doet API-call of mist melding');
    expect(str_contains($boundaryError,'min="'.$minimum->format('Y-m-d').'"'),'HTML min wijkt af van servergrens');
    $_POST['from']=$minimum->format('Y-m-d');
    ob_start();$boundaryController->handle();ob_end_clean();
    expect(http_response_code()===303&&count($boundaryHttp->paths)===1,'Exacte grensdatum geweigerd');
    $_POST['from']=$minimum->modify('+1 day')->format('Y-m-d');
    ob_start();$boundaryController->handle();ob_end_clean();
    expect(http_response_code()===400&&count($boundaryHttp->paths)===1,'Einddatum vóór begindatum toegestaan');

    // Unknown names cause one bulk catalog fetch, then session reuse on the next fetch.
    \App\Toggl\TogglSession::open($config);
    unset($_SESSION['toggl_projects'], $_SESSION['toggl_projects_complete']);
    session_write_close();
    $catalogHttp = new TimeHttp([batch([entry(1)]), batch([['id' => 10, 'name' => 'Project']]), batch([entry(1)])]);
    $catalogController = new \App\Toggl\TimeEntryController($config, new View(dirname(__DIR__) . '/templates'), $catalogHttp);
    $_POST = ['csrf' => $csrf, 'from' => '2026-09-01', 'to' => '2026-09-01', 'action' => 'fetch'];
    ob_start(); $catalogController->handle(); ob_end_clean();
    expect(count($catalogHttp->paths) === 2 && $catalogHttp->paths[1] === '/me/projects?include_archived=true', 'Projecten niet in bulk opgehaald');
    ob_start(); $catalogController->handle(); ob_end_clean();
    expect(count($catalogHttp->paths) === 3, 'Projectcatalogus onnodig opnieuw opgehaald');
    // Quota saved in the shared session blocks future time-entry and catalog requests.
    \App\Toggl\TogglSession::open($config);
    $_SESSION['toggl_quota'] = ['user' => ['blocked_until' => time() + 60]];
    session_write_close();
    ob_start(); $catalogController->handle(); ob_end_clean();
    expect(count($catalogHttp->paths) === 3, 'Sessielimiet genegeerd');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (isset($sessionId)) {
        $sessionFile = dirname(__DIR__) . '/storage/sess_' . $sessionId;
        if (is_file($sessionFile)) { unlink($sessionFile); }
    }
    unlink($temp . '/.env'); rmdir($temp);
}
echo "Time-entry-tests geslaagd: datums/DST, parsing, groepering, integer-totalen, running, facturatietags, filter, selectie, escaping en quota-bewust splitsen.\n";
