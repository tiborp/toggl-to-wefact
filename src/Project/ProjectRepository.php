<?php
declare(strict_types=1);
namespace App\Project;

use PDO;
use Throwable;

/** Maintains the Toggl project catalog while preserving missing projects and their mappings. */
final class ProjectRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Persist a fully validated catalog atomically; missing projects become unavailable, not deleted.
     * Retaining their IDs protects local mappings and audit references across archive/rename changes.
     */
    public function synchronize(array $projects, string $fingerprint): int
    {
        $started = false;
        try {
            $this->pdo->beginTransaction(); $started = true;
            $now = gmdate('c');
            // Only a complete, validated catalog reaches this point. Preserve missing rows and mappings.
            $this->pdo->exec('UPDATE toggl_projects SET available = 0, active = 0');
            $upsert = $this->pdo->prepare('INSERT INTO toggl_projects (toggl_project_id, toggl_workspace_id, name, active, available, fetched_at, credential_fingerprint) VALUES (?, ?, ?, ?, 1, ?, ?) ON CONFLICT(toggl_project_id) DO UPDATE SET toggl_workspace_id = excluded.toggl_workspace_id, name = excluded.name, active = excluded.active, available = 1, fetched_at = excluded.fetched_at, credential_fingerprint = excluded.credential_fingerprint');
            $seen = [];
            foreach ($projects as $project) {
                if (isset($seen[$project['id']])) { throw new ProjectException('Dubbel project-ID in synchronisatie.'); }
                $seen[$project['id']] = true;
                $upsert->execute([$project['id'], $project['workspace_id'], $project['name'], (int) $project['active'], $now, $fingerprint]);
            }
            $meta = $this->pdo->prepare('INSERT INTO toggl_project_sync (id, credential_fingerprint, fetched_at, project_count) VALUES (1, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET credential_fingerprint = excluded.credential_fingerprint, fetched_at = excluded.fetched_at, project_count = excluded.project_count');
            $meta->execute([$fingerprint, $now, count($projects)]);
            $this->pdo->commit();
            return count($projects);
        } catch (Throwable) {
            if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw new ProjectException('Projecten konden niet worden opgeslagen. De vorige projectgegevens en mappings blijven behouden.');
        }
    }

    public function all(string $fingerprint, string $search = ''): array
    {
        try {
            $query = $this->pdo->prepare('SELECT * FROM toggl_projects WHERE credential_fingerprint = ?');
            $query->execute([$fingerprint]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($search !== '') {
                $needle = mb_strtolower($search, 'UTF-8');
                $rows = array_values(array_filter($rows, static fn ($row) => str_contains(mb_strtolower($row['name'], 'UTF-8'), $needle)));
            }
            usort($rows, static fn ($a, $b) => strnatcmp(mb_strtolower($a['name'], 'UTF-8'), mb_strtolower($b['name'], 'UTF-8')) ?: $a['toggl_project_id'] <=> $b['toggl_project_id']);
            return $rows;
        } catch (Throwable) { throw new ProjectException('De lokale projecten konden niet worden gelezen. Controleer SQLite.'); }
    }

    public function metadata(string $fingerprint): ?array
    {
        $query = $this->pdo->prepare('SELECT fetched_at, project_count FROM toggl_project_sync WHERE id = 1 AND credential_fingerprint = ?');
        $query->execute([$fingerprint]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
