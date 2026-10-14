<?php
declare(strict_types=1);

use App\WeFact\{WeFactClient, HttpTransport, HttpResponse, WeFactException, DebtorCache, DebtorService, StatusController};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
require dirname(__DIR__) . '/vendor/autoload.php';

function assertWeFact(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
final class WeFactStub implements HttpTransport {
    public array $requests = [];
    public function __construct(private array $responses) {}
    public function listDebtors(#[\SensitiveParameter] string $key, int $offset, int $limit): HttpResponse {
        // Deliberately record only pagination, never credentials.
        $this->requests[] = ['offset' => $offset, 'limit' => $limit];
        $result = array_shift($this->responses);
        if ($result instanceof WeFactException) { throw $result; }
        if (!$result instanceof HttpResponse) { throw new RuntimeException('Onverwachte extra API-call'); }
        return $result;
    }
}
function wefactRow(int $id, string $company = 'Bedrijf', string $first = '', string $last = ''): array {
    return ['Identifier' => (string) $id, 'DebtorCode' => 'DB' . $id, 'CompanyName' => $company, 'Initials' => $first, 'SurName' => $last, 'City' => 'Utrecht', 'EmailAddress' => 'test@example.invalid'];
}
function wefactPage(array $rows, ?int $total = null, int $offset = 0): HttpResponse {
    return new HttpResponse(200, json_encode(['controller' => 'debtor', 'action' => 'list', 'status' => 'success', 'totalresults' => $total ?? count($rows), 'currentresults' => count($rows), 'offset' => $offset, 'debtors' => $rows], JSON_THROW_ON_ERROR));
}
function assertWeFactFailure(callable $call, string $kind): void {
    try { $call(); } catch (WeFactException $error) {
        assertWeFact($error->kind === $kind, 'Verkeerde foutcategorie: ' . $error->kind);
        assertWeFact(!str_contains($error->getMessage(), 'test-secret-key'), 'Credential in foutmelding');
        assertWeFact($error->getPrevious() === null, 'Ruwe exception chained');
        return;
    }
    throw new RuntimeException('Verwachte fout ontbreekt');
}
$http = new WeFactStub([]);
assertWeFactFailure(fn () => (new WeFactClient('', $http))->debtors(), 'missing_key');
assertWeFact($http->requests === [], 'API-call zonder key');
$company = wefactRow(42, 'Zebra BV');
$person = wefactRow(57, '', 'Anne', 'Jansen');
$http = new WeFactStub([wefactPage([$company, $person])]);
$client = new WeFactClient('test-secret-key', $http);
$rows = $client->debtors();
assertWeFact($rows[0]['wefact_debtor_id'] === '42' && $rows[0]['debtor_number'] === 'DB42', 'Stabiele Identifier verkeerd');
assertWeFact($rows[1]['display_name'] === 'Anne Jansen' && $rows[0]['display_name'] === 'Zebra BV', 'Displaynaam onjuist');
assertWeFact($rows[0]['city'] === 'Utrecht' && $rows[0]['email'] === 'test@example.invalid', 'Debiteurenvelden verkeerd');
assertWeFact($client->status()['connected'] && $client->status()['authenticated'], 'Successtatus ontbreekt');
assertWeFact($http->requests === [['offset' => 0, 'limit' => 1000]], 'Geen minimale bulk-opvraag');
$http = new WeFactStub([wefactPage([$company], 2), wefactPage([$person], 2, 1)]);
assertWeFact(count((new WeFactClient('test-secret-key', $http))->debtors()) === 2, 'Pagination ontbreekt');
assertWeFact(array_column($http->requests, 'offset') === [0, 1], 'Offset niet op currentresults gebaseerd');
$http = new WeFactStub([wefactPage([], 0)]);
assertWeFact((new WeFactClient('test-secret-key', $http))->debtors() === [] && count($http->requests) === 1, 'Lege administratie onjuist');
$minimal = wefactRow(1, '', '', ''); unset($minimal['City'], $minimal['EmailAddress']);
$http = new WeFactStub([wefactPage([$minimal])]);
$minimalRows = (new WeFactClient('test-secret-key', $http))->debtors();
assertWeFact($minimalRows[0]['display_name'] === 'DB1' && $minimalRows[0]['city'] === '', 'Fallback/optionele velden ontbreken');
$leak = wefactRow(1, 'test-secret-key');
$http = new WeFactStub([wefactPage([$leak])]);
assertWeFact((new WeFactClient('test-secret-key', $http))->debtors()[0]['company_name'] === '[afgeschermd]', 'Key in displaydata');

$failures = [
    [new HttpResponse(200, '{"status":"error","errors":["Invalid API key test-secret-key"]}'), 'authentication'],
    [new HttpResponse(403, '{"status":"error","errors":["IP not whitelisted test-secret-key"]}'), 'ip'],
    [new HttpResponse(403, 'forbidden'), 'access'],
    [new HttpResponse(200, '{"status":"error","controller":"debtor","errors":["Invalid request test-secret-key"]}'), 'api'],
    [new HttpResponse(500, '{}'), 'http'],
    [new HttpResponse(200, 'not json test-secret-key'), 'json'],
    [new HttpResponse(200, '[]'), 'response'],
    [new HttpResponse(200, '{"status":"success"}'), 'response'],
    [new WeFactException('WeFact is niet bereikbaar.', 'network', false), 'network'],
    [new WeFactException('WeFact antwoordde niet op tijd.', 'timeout', false), 'timeout'],
];
foreach ($failures as [$response, $kind]) {
    $http = new WeFactStub([$response]);
    assertWeFactFailure(fn () => (new WeFactClient('test-secret-key', $http))->debtors(), $kind);
    assertWeFact(count($http->requests) === 1, 'Automatische retry bij fout');
}
foreach ([
    [wefactPage([$company], 2), wefactPage([$person], 3, 1)],
    [wefactPage([$company], 2), wefactPage([], 2, 1)],
    [wefactPage([$company], 2), wefactPage([$company], 2, 1)],
] as $pages) {
    assertWeFactFailure(fn () => (new WeFactClient('test-secret-key', new WeFactStub($pages)))->debtors(), 'pagination');
}
$bad = wefactRow(1); $bad['Identifier'] = null;
assertWeFactFailure(fn () => (new WeFactClient('test-secret-key', new WeFactStub([wefactPage([$bad])])))->debtors(), 'response');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
assertWeFact($migrator->migrate() === 12 && $migrator->migrate() === 0, 'Migrations niet herhaalbaar');
$cache = new DebtorCache($pdo);
$fingerprint = hash('sha256', 'test-secret-key');
$client = new WeFactClient('test-secret-key', new WeFactStub([wefactPage([$company, $person])]));
$refresh = (new DebtorService($client, $cache))->refresh($fingerprint);
assertWeFact($refresh['error'] === null && $refresh['count'] === 2, 'Refresh mislukt');
$cached = $cache->read($fingerprint);
assertWeFact($cached['debtors'][0]['display_name'] === 'Anne Jansen', 'Sortering onjuist');
assertWeFact($cached['metadata']['debtor_count'] === 2 && $cached['debtors'][0]['wefact_debtor_id'] === '57', 'SQLite-ID/metadata niet opgeslagen');
assertWeFact($cached['debtors'][0]['fetched_at'] !== '', 'Fetched_at ontbreekt');
assertWeFact(count($cache->read($fingerprint, 'ZEBRA')['debtors']) === 1, 'Lokale zoekactie onjuist');
assertWeFact($cache->read('another-key')['metadata'] === null, 'Cache van andere key getoond');

$failedClient = new WeFactClient('test-secret-key', new WeFactStub([wefactPage([$company], 2), new HttpResponse(200, 'invalid JSON')]));
$failed = (new DebtorService($failedClient, $cache))->refresh($fingerprint);
assertWeFact($failed['error'] !== null && $cache->read($fingerprint) === $cached, 'Gedeeltelijke refresh vernietigt cache');
$pdo->exec("CREATE TRIGGER prevent_cache_insert BEFORE INSERT ON wefact_debtors BEGIN SELECT RAISE(ABORT, 'test write failure'); END");
$failed = (new DebtorService(new WeFactClient('test-secret-key', new WeFactStub([wefactPage([$company])])), $cache))->refresh($fingerprint);
assertWeFact($failed['error'] !== null && $cache->read($fingerprint) === $cached, 'Schrijffout vernietigt cache');
$pdo->exec('DROP TRIGGER prevent_cache_insert');

// Controller GET/search are entirely local; POST alone performs read-only list calls.
$temp = sys_get_temp_dir() . '/wefact-controller-' . bin2hex(random_bytes(8));
mkdir($temp); file_put_contents($temp . '/.env', "WEFACT_API_KEY=test-secret-key\n");
$old = getenv('WEFACT_API_KEY');
try {
    putenv('WEFACT_API_KEY=environment-secret');
    $config = new Config($temp);
    assertWeFact($config->wefactKey() === 'test-secret-key', 'Key uit omgeving gelezen');
    $http = new WeFactStub([wefactPage([wefactRow(99, '<script>"&')])]);
    $controller = new StatusController($config, new View(dirname(__DIR__) . '/templates'), $cache, $http);
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = []; $_POST = [];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    $sessionId = session_id();
    assertWeFact($http->requests === [] && str_contains($html, 'Anne Jansen'), 'GET haalt API op of toont cache niet');
    preg_match('/name="csrf" value="([^"]+)"/', $html, $match); $csrf = $match[1];
    $_GET = ['q' => 'ZEBRA'];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    assertWeFact($http->requests === [] && str_contains($html, 'Zebra BV') && !str_contains($html, 'Anne Jansen'), 'Zoeken haalt API op');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = []; $_POST = ['csrf' => 'invalid', 'action' => 'refresh'];
    ob_start(); $controller->handle(); ob_end_clean();
    assertWeFact(http_response_code() === 403 && $http->requests === [], 'CSRF niet beschermd');
    $_POST = ['csrf' => $csrf, 'action' => 'refresh'];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    assertWeFact(http_response_code() === 303 && $html === '' && count($http->requests) === 1, 'Expliciete refresh niet uitgevoerd');
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];
    ob_start(); $controller->handle(); $html = ob_get_clean();
    assertWeFact(count($http->requests) === 1 && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'GET herhaalt request of HTML-escaping ontbreekt');
    assertWeFact(!str_contains($html, 'test-secret-key') && !str_contains($html, 'environment-secret'), 'Key in HTML');
    unlink($temp . '/.env');
    assertWeFact((new Config($temp))->wefactKey() === '', 'Omgevingskey zonder .env gebruikt');
    // A fully successful empty response must replace the old cache too.
    $empty = (new DebtorService(new WeFactClient('test-secret-key', new WeFactStub([wefactPage([])])), $cache))->refresh($fingerprint);
    assertWeFact($empty['error'] === null && $cache->read($fingerprint)['metadata']['debtor_count'] === 0, 'Lege volledige cache niet verwerkt');
} finally {
    putenv($old === false ? 'WEFACT_API_KEY' : 'WEFACT_API_KEY=' . $old);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (isset($sessionId)) { $file = dirname(__DIR__) . '/storage/sess_' . $sessionId; if (is_file($file)) { unlink($file); } }
    if (is_file($temp . '/.env')) { unlink($temp . '/.env'); } rmdir($temp);
}
echo "WeFact-tests geslaagd: keybron, parsing, Identifier, pagination, fouten/IP/timeout, transactionele cache, filter/sortering, CSRF, escaping en requestaantal.\n";
