<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException();
    }
    $arguments = App\Runtime\LocalServer::arguments(dirname(__DIR__));
} catch (Throwable) {
    fwrite(STDERR, "Lokale webserver kon niet starten. Controleer storage/logs en de schrijfrechten.\n");
    exit(1);
}

// exec keeps the server in the foreground so Ctrl+C stops it normally on macOS.
passthru('exec ' . implode(' ', array_map('escapeshellarg', $arguments)), $status);
exit($status);
