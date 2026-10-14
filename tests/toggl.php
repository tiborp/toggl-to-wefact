<?php
declare(strict_types=1);

use App\Config\Config;
use App\Toggl\{HttpTransport, HttpResponse, Quota, TogglClient, TogglException, StatusService};
use App\View\View;
require dirname(__DIR__) . '/vendor/autoload.php';

function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
final class StubHttp implements HttpTransport {
    public array $paths = [];
    public function __construct(private array $responses) {}
    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse {
        $this->paths[] = $path;
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        if (!$response instanceof HttpResponse) { throw new RuntimeException('Unexpected extra request'); }
        return $response;
    }
}
function response(mixed $body, int $status = 200, array $headers = []): HttpResponse {
    return new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR), $headers);
}
function fails(callable $call, string $text): void {
    try { $call(); } catch (TogglException $error) {
        verify(str_contains($error->getMessage(), $text), 'Verkeerde foutmelding');
        verify(!str_contains($error->getMessage(), 'stub-secret'), 'Credential in foutmelding');
        return;
    }
    throw new RuntimeException('Fout werd niet gedetecteerd');
}
$user = response(['id' => 1, 'fullname' => 'Test User', 'email' => 'test@example.invalid', 'api_token' => 'stub-secret']);
$workspaces = response([['id' => 10, 'name' => 'One', 'organization_id' => 5], ['id' => 20, 'name' => 'Two', 'organization_id' => 5]]);
$projects = response([['id' => 30, 'name' => '<Example>']]);
$http = new StubHttp([$user, $workspaces, $projects]);
$client = new TogglClient('stub-secret', $http, new Quota());
$result = (new StatusService($client))->load('20');
verify($result['connected'] && $result['error'] === null, 'Authenticatie/parsing mislukt');
verify($result['selected'] === 20 && $result['projects'][0]['id'] === 30, 'Workspace/projectdata mislukt');
verify($result['user']['name'] === 'Test User' && !isset($result['user']['api_token']), 'Userdata niet veilig');
verify($http->paths === ['/me', '/me/workspaces', '/workspaces/20/projects'], 'Geen drie bulk GET endpoints');
$client->user(); $client->workspaces(); $client->projects(20, 5);
verify(count($http->paths) === 3, 'Dubbele request tijdens actie');

$http = new StubHttp([]);
fails(fn () => (new TogglClient('', $http, new Quota()))->user(), 'ontbreekt');
verify($http->paths === [], 'Request zonder token');
foreach ([401, 403] as $code) {
    $http = new StubHttp([new HttpResponse($code, 'stub-secret')]);
    $result = (new StatusService(new TogglClient('stub-secret', $http, new Quota())))->load();
    verify(!$result['connected'] && str_contains($result['error'], 'geweigerd') && count($http->paths) === 1, 'Auth error niet gestopt');
    verify(!str_contains($result['error'], 'stub-secret'), 'Responsebody gelekt');
}
foreach ([402, 429] as $code) {
    $quota = new Quota();
    $http = new StubHttp([response(null, $code, ['X-Toggl-Quota-Remaining' => '0', 'X-Toggl-Quota-Resets-In' => '90'])]);
    $client = new TogglClient('stub-secret', $http, $quota);
    fails(fn () => $client->user(), 'bereikt');
    $state = $quota->state();
    verify($state['user']['remaining'] === 0 && $state['user']['reset_at'] >= time() + 89, 'Quota headers parsing');
    $next = new StubHttp([]);
    fails(fn () => (new TogglClient('stub-secret', $next, new Quota($state)))->user(), 'bereikt');
    verify(count($http->paths) === 1 && $next->paths === [], 'Quota veroorzaakte retry');
}
$quota = new Quota();
$http = new StubHttp([response(['id' => 1], 200, ['x-toggl-quota-remaining' => '0'])]);
$result = (new StatusService(new TogglClient('stub-secret', $http, $quota)))->load();
verify($result['connected'] && count($http->paths) === 1 && $result['error'] !== null, '200 met nul quota niet gestopt');
$quota = new Quota();
$quota->record('organization:5', response(null, 402));
fails(fn () => $quota->assertAvailable('organization:5'), 'bereikt');
$quota->assertAvailable('organization:6');
$expired = new Quota(['user' => ['blocked_until' => time() - 1]]);
$expired->assertAvailable('user');

foreach (['not json', '{}', 'null', '{"unexpected":true}'] as $body) {
    $http = new StubHttp([new HttpResponse(200, $body)]);
    fails(fn () => (new TogglClient('stub-secret', $http, new Quota()))->user(), 'onverwachte');
}
foreach (['{}', 'null', '[{"id":"10","name":"One"}]'] as $body) {
    $http = new StubHttp([new HttpResponse(200, $body)]);
    fails(fn () => (new TogglClient('stub-secret', $http, new Quota()))->workspaces(), 'onverwachte');
}
$http = new StubHttp([response([])]);
verify((new TogglClient('stub-secret', $http, new Quota()))->projects(10) === [], 'Lege projectenlijst');
$http = new StubHttp([$user, $workspaces]);
$result = (new StatusService(new TogglClient('stub-secret', $http, new Quota())))->load('999');
verify($result['error'] !== null && count($http->paths) === 2, 'Onbeschikbare workspace niet geweigerd');
$http = new StubHttp([]);
$result = (new StatusService(new TogglClient('stub-secret', $http, new Quota())))->load('invalid');
verify($result['error'] !== null && $http->paths === [], 'Ongeldige input veroorzaakte API-requests');
$http = new StubHttp([new TogglException('Toggl is niet bereikbaar.')]);
fails(fn () => (new TogglClient('stub-secret', $http, new Quota()))->user(), 'niet bereikbaar');
$http = new StubHttp([response(null, 503)]);
fails(fn () => (new TogglClient('stub-secret', $http, new Quota()))->user(), 'niet beschikbaar');
$http = new StubHttp([response(['id' => 1, 'fullname' => 'stub-secret'])]);
verify((new TogglClient('stub-secret', $http, new Quota()))->user()['name'] === '[afgeschermd]', 'Credential in displaydata');

// Environment token is ignored, even when there is no .env file.
$temp = sys_get_temp_dir() . '/toggl-config-' . bin2hex(random_bytes(8));
mkdir($temp);
$old = getenv('TOGGL_API_TOKEN');
try {
    putenv('TOGGL_API_TOKEN=environment-stub');
    verify((new Config($temp))->togglToken() === '', 'Token gelezen uit omgeving');
    file_put_contents($temp . '/.env', "TOGGL_API_TOKEN=stub-secret\n");
    verify((new Config($temp))->togglToken() === 'stub-secret', 'Token niet uit .env gelezen');
} finally {
    putenv($old === false ? 'TOGGL_API_TOKEN' : 'TOGGL_API_TOKEN=' . $old);
    if (is_file($temp . '/.env')) { unlink($temp . '/.env'); }
    rmdir($temp);
}
// Render untrusted API names through the actual template.
$status = ['connected' => true, 'user' => ['name' => '<script>', 'email' => 'x'], 'workspaces' => [['id' => 10, 'name' => '<script>']], 'selected' => 10, 'projects' => [['id' => 30, 'name' => '<script>']], 'error' => null, 'loaded_at' => 'test'];
ob_start();
(new View(dirname(__DIR__) . '/templates'))->page('Test', 'Toggl', '', '/toggl', ['status' => $status, 'quotas' => [], 'csrf' => 'test', 'error' => null]);
$html = ob_get_clean();
verify(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'API-data HTML escaping');
echo "Toggl-tests geslaagd: authenticatie, tokenbron, quota/blokkering, fouten, parsing, selectie, requestaantal en HTML escaping.\n";
