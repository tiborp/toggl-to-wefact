<?php
declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/** Loads local configuration while keeping API credentials independent of environment overrides. */
final class Config
{
    private array $values = [];

    public function __construct(private readonly string $root)
    {
        $file = $root . '/.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                throw new RuntimeException('Ongeldige configuratieregel in .env.');
            }
            $value = trim($matches[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $this->values[$matches[1]] = $value;
        }
    }

    public function get(string $key, string $default = ''): string
    {
        $environment = getenv($key);
        return $environment !== false ? $environment : ($this->values[$key] ?? $default);
    }

    public function togglToken(): string
    {
        // Credentials must come exclusively from the local .env, never getenv().
        return trim($this->values['TOGGL_API_TOKEN'] ?? '');
    }

    public function wefactKey(): string
    {
        return trim($this->values['WEFACT_API_KEY'] ?? '');
    }

    public function databasePath(): string
    {
        $path = $this->get('DB_PATH', 'storage/app.sqlite');
        if ($path === '') {
            throw new RuntimeException('DB_PATH mag niet leeg zijn.');
        }
        return str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
    }
}
