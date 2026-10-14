<?php
declare(strict_types=1);

namespace App\Runtime;

use RuntimeException;

/** Prepares private error logging and process-local PHP options for the localhost server. */
final class LocalServer
{
    /**
     * Create/protect the local log and return server arguments without changing global php.ini.
     * @return list<string> PHP binary, process-local settings, localhost binding and router arguments.
     */
    public static function arguments(string $root): array
    {
        $directory = $root . '/storage/logs';
        $log = $directory . '/php-error.log';
        $previous = umask(0077);
        try {
            if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true))) {
                throw new RuntimeException('Lokale logdirectory kon niet worden voorbereid.');
            }
            if (is_link($log) || (file_exists($log) && !is_file($log))) {
                throw new RuntimeException('Ongeldig lokaal logbestand.');
            }
            $handle = fopen($log, 'ab');
            if ($handle === false) {
                throw new RuntimeException('Lokaal logbestand is niet schrijfbaar.');
            }
            fclose($handle);
            if (!chmod($directory, 0700) || !chmod($log, 0600)) {
                throw new RuntimeException('Lokale logrechten konden niet worden ingesteld.');
            }
        } finally {
            umask($previous);
        }

        return [
            PHP_BINARY,
            '-d', 'display_errors=Off',
            '-d', 'display_startup_errors=Off',
            '-d', 'log_errors=On',
            '-d', 'error_reporting=E_ALL',
            '-d', 'error_log=' . $log,
            '-S', '127.0.0.1:8080',
            '-t', $root . '/public',
            $root . '/public/router.php',
        ];
    }
}
