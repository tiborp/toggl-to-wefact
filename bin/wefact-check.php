<?php
declare(strict_types=1);

use App\WeFact\{WeFactClient, CurlTransport, DebtorCache, DebtorService};
$app = require dirname(__DIR__) . '/bootstrap.php';
$key = $app['config']->wefactKey();
if ($key === '') { echo "Geen WeFact-key in .env; echte API niet benaderd.\n"; exit(0); }
$client = new WeFactClient($key, new CurlTransport());
$result = (new DebtorService($client, new DebtorCache($app['pdo'])))->refresh(hash('sha256', $key));
echo 'API-requests: ' . $result['requests'] . "\n";
if ($result['error'] !== null) { echo $result['error'] . "\n"; exit(1); }
echo 'Verbinding en authenticatie succesvol. Debiteuren: ' . $result['count'] . "\n";
echo "Volledige SQLite-cache bijgewerkt; uitsluitend debtor/list uitgevoerd.\n";
