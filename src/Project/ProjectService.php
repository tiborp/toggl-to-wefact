<?php
declare(strict_types=1);
namespace App\Project;

use App\WeFact\DebtorCache;

/** Combines local projects, debtor cache and mappings into validated billing configuration. */
final class ProjectService
{
    public function __construct(private readonly ProjectRepository $projects, private readonly MappingRepository $mappings, private readonly DebtorCache $debtors, private readonly string $togglFingerprint, private readonly string $wefactFingerprint) {}

    public function overview(string $search = ''): array
    {
        $cached = $this->debtors->read($this->wefactFingerprint);
        $debtors = [];
        foreach ($cached['debtors'] as $debtor) { $debtors[$debtor['wefact_debtor_id']] = $debtor; }
        $mappings = $this->mappings->all();
        $rows = [];
        foreach ($this->projects->all($this->togglFingerprint, $search) as $project) {
            $mapping = $mappings[$project['toggl_project_id']] ?? null;
            $debtor = $mapping !== null && $mapping['wefact_credential_fingerprint'] === $this->wefactFingerprint ? ($debtors[$mapping['wefact_debtor_id']] ?? null) : null;
            $status = 'Niet gekoppeld';
            if ($mapping !== null && $mapping['wefact_debtor_id'] !== null) {
                $status = $debtor === null ? 'Debiteur ontbreekt' : ($mapping['hourly_rate_cents'] === null ? 'Onvolledig' : 'Gereed');
            }
            $rows[] = $project + ['mapping' => $mapping, 'debtor' => $debtor, 'status' => $status,
                'ready' => $this->configurationIssue($project + ['mapping' => $mapping, 'debtor' => $debtor]) === null];
        }
        return ['projects' => $rows, 'debtors' => array_values($debtors), 'debtor_metadata' => $cached['metadata'], 'sync' => $this->projects->metadata($this->togglFingerprint)];
    }

    public function configurationIssue(?array $project): ?string
    {
        if ($project === null) { return 'Project niet lokaal bekend of entry heeft geen project.'; }
        if (!$project['available'] || !$project['active']) { return 'Project is niet actief/beschikbaar.'; }
        if (($project['mapping']['wefact_debtor_id'] ?? null) === null) { return 'Project is nog niet gekoppeld aan een WeFact-debiteur.'; }
        if ($project['debtor'] === null) { return 'Gekoppelde WeFact-debiteur ontbreekt in de huidige cache.'; }
        $rate = $project['mapping']['hourly_rate_cents'];
        if (!is_int($rate) || $rate <= 0) { return 'Uurtarief ontbreekt of is ongeldig.'; }
        return null;
    }

    public function save(mixed $projectId, mixed $debtorId, mixed $rate): void
    {
        $id = $this->projectId($projectId);
        if (!is_string($debtorId) || ($debtorId !== '' && !preg_match('/^[1-9][0-9]{0,17}$/D', $debtorId))) {
            throw new ProjectException('Selecteer een geldige WeFact-debiteur.');
        }
        $this->mappings->save($id, $debtorId === '' ? null : $debtorId, HourlyRate::parse($rate), $this->togglFingerprint, $this->wefactFingerprint);
    }

    public function remove(mixed $projectId): void
    {
        $this->mappings->remove($this->projectId($projectId), $this->togglFingerprint);
    }

    // Local future-invoicing lookup. Incomplete, inactive or missing sources cannot be used.
    public function configuration(int $projectId): ?array
    {
        foreach ($this->overview()['projects'] as $project) {
            if ($project['toggl_project_id'] === $projectId && $project['ready']) {
                return ['wefact_debtor_id' => $project['mapping']['wefact_debtor_id'], 'hourly_rate_cents' => $project['mapping']['hourly_rate_cents']];
            }
        }
        return null;
    }

    private function projectId(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) { throw new ProjectException('Ongeldig Toggl-project-ID.'); }
        return (int) $value;
    }
}
