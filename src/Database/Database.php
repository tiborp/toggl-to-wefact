<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/** Opens an existing administration without silently creating a replacement database. */
final class Database
{
    public static function connect(string $path): PDO
    {
        if(!is_file($path)){throw new AdministrationException('De lokale administratie ontbreekt. Herstel de database uit een gecontroleerde back-up. Alleen bij een echte eerste installatie: composer db:init.');}
        $uri='sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=rw';
        $pdo = new PDO($uri, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }
}
