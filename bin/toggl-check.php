<?php
declare(strict_types=1);

use App\Config\Config;
use App\Toggl\{CurlTransport, Quota, TogglClient, StatusService};
require dirname(__DIR__) . '/vendor/autoload.php';
$config = new Config(dirname(__DIR__));
if ($config->togglToken() === '') {
    echo "Geen Toggl-token in .env; echte API niet benaderd.\n";
    exit(0);
}
$quota = new Quota();
$result = (new StatusService(new TogglClient($config->togglToken(), new CurlTransport(), $quota)))->load();
echo $result['connected'] ? "Toggl-account geauthenticeerd.\n" : "Toggl-account niet geauthenticeerd.\n";
echo 'Workspaces: ' . count($result['workspaces']) . "\n";
echo 'Projecten: ' . ($result['projects'] !== null ? count($result['projects']) : 'niet opgehaald') . "\n";
if ($result['error'] !== null) {
    echo $result['error'] . "\n";
    exit(1);
}
echo "Read-only verificatie succesvol (maximaal drie GET-requests).\n";
