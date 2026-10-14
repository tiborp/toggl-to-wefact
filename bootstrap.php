<?php
declare(strict_types=1);

use App\Config\Config;
use App\Database\Database;
use App\Database\Migrator;

require __DIR__ . '/vendor/autoload.php';
$config = new Config(__DIR__);
date_default_timezone_set($config->get('APP_TIMEZONE', 'Europe/Amsterdam'));
$pdo = Database::connect($config->databasePath());
$migrator = new Migrator($pdo, __DIR__ . '/database/migrations');
\App\Database\Maintenance::validate($pdo,__DIR__.'/database/migrations',true);
return ['config' => $config, 'pdo' => $pdo, 'migrator' => $migrator];
