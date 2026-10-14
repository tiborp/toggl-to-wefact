<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/** Applies ordered SQL migrations once, with a transaction per migration. */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory) {}

    /**
     * Apply each missing migration under a writer lock; already recorded versions are skipped.
     * @return int Number of newly applied migration files.
     */
    public function migrate(): int
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);
        $count = 0;
        foreach ($files as $file) {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $version = basename($file);
                $check = $this->pdo->prepare('SELECT version FROM migrations WHERE version = ?');
                $check->execute([$version]);
                if (!$check->fetchColumn()) {
                    $sql = file_get_contents($file);
                    if ($sql === false) {
                        throw new RuntimeException('Migration kon niet worden gelezen.');
                    }
                    $this->pdo->exec($sql);
                    $insert = $this->pdo->prepare('INSERT INTO migrations (version, applied_at) VALUES (?, ?)');
                    $insert->execute([$version, gmdate('c')]);
                    $count++;
                }
                $this->pdo->exec('COMMIT');
            } catch (Throwable $error) {
                $this->pdo->exec('ROLLBACK');
                throw $error;
            }
        }
        return $count;
    }
}
