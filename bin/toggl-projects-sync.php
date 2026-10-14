<?php
declare(strict_types=1);

use App\Toggl\{TogglClient, CurlTransport, Quota, TogglException};
use App\Project\{ProjectRepository, ProjectSync, ProjectException};
$app = require dirname(__DIR__) . '/bootstrap.php';
$token = $app['config']->togglToken();
if ($token === '') { echo "Geen Toggl-token in .env; API niet benaderd.\n"; exit(0); }
try {
    $count = (new ProjectSync(new TogglClient($token, new CurlTransport(), new Quota()), new ProjectRepository($app['pdo'])))->refresh(hash('sha256', $token));
    echo 'Projecten ontvangen en lokaal opgeslagen: ' . $count . "\n";
    echo "Eén bulk GET-request; geen mappings aangemaakt en geen WeFact-calls uitgevoerd.\n";
} catch (ProjectException | TogglException $error) {
    echo $error->getMessage() . "\n";
    exit(1);
}
