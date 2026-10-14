<?php
declare(strict_types=1);

namespace App\Runtime;

use RuntimeException;

/** Prepares private runtime storage without creating or migrating the administration. */
final class Storage
{
    public static function directory(string $root): string
    {
        $directory = $root . '/storage';
        $previous = umask(0077);
        try {
            if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
                throw new RuntimeException('De lokale runtime-directory kon niet veilig worden aangemaakt.');
            }
            if (!@chmod($directory, 0700) || !is_writable($directory)) {
                throw new RuntimeException('De lokale runtime-directory is niet veilig schrijfbaar.');
            }
        } finally {
            umask($previous);
        }
        return $directory;
    }
}
