<?php
declare(strict_types=1);
namespace App\Project;

use PDO;
use Throwable;

/** Stores local project rates and debtor links without coupling their lifetime to API caches. */
final class MappingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $projectId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM project_mappings WHERE toggl_project_id = ?');
        $query->execute([$projectId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM project_mappings')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) { $result[$row['toggl_project_id']] = $row; }
        return $result;
    }

    /**
     * Store a local debtor link/rate under the current source fingerprints.
     * An existing missing debtor may survive a rate edit; a new missing link is rejected.
     * No remote project, debtor or invoice is modified.
     */
    public function save(int $projectId, ?string $debtorId, ?int $rate, string $togglFingerprint, string $wefactFingerprint): void
    {
        $started = false;
        try {
            $this->pdo->beginTransaction(); $started = true;
            $this->assertProject($projectId, $togglFingerprint);
            if ($rate !== null && $rate <= 0) { throw new ProjectException('Het uurtarief moet groter dan nul zijn.'); }
            if ($debtorId !== null) {
                $check = $this->pdo->prepare('SELECT d.wefact_debtor_id FROM wefact_debtors d JOIN wefact_cache_state s ON s.id = 1 WHERE d.wefact_debtor_id = ? AND s.credential_fingerprint = ?');
                $check->execute([$debtorId, $wefactFingerprint]);
                $existing = $this->find($projectId);
                // Retain an already missing debtor on a rate-only edit; never create a new missing link.
                if ($existing !== null && $existing['wefact_debtor_id'] === $debtorId && $existing['wefact_credential_fingerprint'] !== $wefactFingerprint) {
                    throw new ProjectException('Deze koppeling hoort bij een eerdere WeFact-configuratie. Verwijder de koppeling en kies daarna bewust een debiteur uit de huidige cache.');
                }
                $unchanged = $existing !== null && $existing['wefact_debtor_id'] === $debtorId && $existing['wefact_credential_fingerprint'] === $wefactFingerprint;
                if (!$check->fetchColumn() && !$unchanged) {
                    throw new ProjectException('Deze WeFact-debiteur bestaat niet in de huidige lokale cache. Vernieuw de debiteuren via WeFact-status of kies een beschikbare debiteur.');
                }
            }
            $now = gmdate('c');
            $query = $this->pdo->prepare('INSERT INTO project_mappings (toggl_project_id, wefact_debtor_id, wefact_credential_fingerprint, hourly_rate_cents, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(toggl_project_id) DO UPDATE SET wefact_debtor_id = excluded.wefact_debtor_id, wefact_credential_fingerprint = excluded.wefact_credential_fingerprint, hourly_rate_cents = excluded.hourly_rate_cents, updated_at = excluded.updated_at');
            $query->execute([$projectId, $debtorId, $debtorId !== null ? $wefactFingerprint : null, $rate, $now, $now]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($error instanceof ProjectException) { throw $error; }
            throw new ProjectException('De projectkoppeling kon niet worden opgeslagen. De bestaande configuratie blijft behouden.');
        }
    }

    public function remove(int $projectId, string $togglFingerprint): void
    {
        $this->assertProject($projectId, $togglFingerprint);
        $query = $this->pdo->prepare('DELETE FROM project_mappings WHERE toggl_project_id = ?');
        $query->execute([$projectId]);
    }

    private function assertProject(int $projectId, string $fingerprint): void
    {
        $query = $this->pdo->prepare('SELECT toggl_project_id FROM toggl_projects WHERE toggl_project_id = ? AND credential_fingerprint = ?');
        $query->execute([$projectId, $fingerprint]);
        if (!$query->fetchColumn()) { throw new ProjectException('Dit Toggl-project bestaat niet in de huidige lokale projectcache.'); }
    }
}
