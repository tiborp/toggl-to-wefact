<?php
declare(strict_types=1);
namespace App\Invoice;

use App\Project\ProjectService;
use App\Toggl\TimeEntryOverview;
use App\Toggl\TimeEntryStatus;
use DateTimeZone;
use DateTimeImmutable;

/** Validates selected entry IDs against current local configuration before project-level calculation. */
final class PreviewService
{
    public function __construct(private readonly ProjectService $projects, private readonly ?LocalInvoiceStatus $local = null) {}

    /**
     * Validate untrusted selection/modes and rebuild per-project models from current local data.
     * No posted duration, price or debtor is trusted; this method performs no external calls.
     * @throws PreviewException If entry IDs, project configuration or selection are invalid.
     */
    public function build(array $hours, mixed $selection, mixed $modes): array
    {
        if ($this->local !== null) { $hours['entries'] = $this->local->annotate($hours['entries']); }
        return $this->buildWithContext($hours, $selection, $modes, $this->context());
    }

    public function projectChoices(array $entries,array $names,bool $archived=false,string $selected=''):array
    {
        foreach($this->context()as$p){$names[$p['toggl_project_id']]=['id'=>$p['toggl_project_id'],'name'=>$p['name'],'active'=>(bool)$p['active']];}
        return \App\Toggl\ProjectChoice::options($entries,$names,$archived,$selected);
    }

    /**
     * Build the editable overview from stored IDs, not from a previously trusted financial model.
     * GET can recalculate locally, but confirmation still requires an explicitly updated model hash.
     */
    public function presentation(array $hours, array $names, DateTimeZone $timezone, ?array $state = null): array
    {
        if ($this->local !== null) { $hours['entries'] = $this->local->annotate($hours['entries']); }
        $context = $this->context();
        foreach ($context as $project) { $names[$project['toggl_project_id']] = ['id' => $project['toggl_project_id'], 'name' => $project['name'],'active'=>(bool)$project['active']]; }
        $overview = (new TimeEntryOverview())->build($hours['entries'], $names, $hours['only_uninvoiced'], $timezone);
        $filter = $hours['project_filter'] ?? '';
        $options = \App\Toggl\ProjectChoice::options($hours['entries'],$names,$hours['show_archived']??false,$hours['project_filter']??'');
        if ($filter !== '') {
            $overview['groups'] = array_values(array_filter($overview['groups'], static fn ($g) => ($g['project_id'] === null ? 'none' : (string) $g['project_id']) === $filter));
        }
        $selection = $state['selection'] ?? [];
        $modes = $state['modes'] ?? [];
        if ($state === null) {
            foreach ($hours['entries'] as $entry) {
                if ($this->allowed($entry, $context, $filter)) { $selection[$entry['project_id']][] = (string) $entry['id']; }
            }
        }
        $result = $this->buildWithContext($hours, $selection, $modes, $context);
        $overview['seconds'] = 0;
        foreach ($overview['groups'] as &$group) {
            $project = $context[$group['project_id']] ?? null;
            $group['issue'] = $this->issue($project);
            $group['selected_seconds'] = $result['previews'][$group['project_id']]['billed_duration_seconds'] ?? 0;
            $group['mode'] = $result['modes'][$group['project_id']] ?? 'summary';
            $group['preview'] = $result['previews'][$group['project_id']] ?? null;
            $group['confirmable'] = $group['preview'] !== null && $hours['complete'] && isset($state['model_hashes'][$group['project_id']]) && hash_equals($state['model_hashes'][$group['project_id']], InvoiceRepository::modelHash($group['preview']));
            $group['prepare_issue'] = null;
            if ($group['preview'] !== null && !$group['confirmable']) {
                $group['prepare_issue'] = !$hours['complete']
                    ? 'De urenopvraag is onvolledig. Haal eerst de volledige periode opnieuw op.'
                    : (!isset($state['model_hashes'][$group['project_id']])
                        ? 'Deze zichtbare preview is nog niet bijgewerkt. Klik eerst op Factuur-preview bijwerken.'
                        : 'De configuratie of selectie is gewijzigd sinds de laatste preview. Klik opnieuw op Factuur-preview bijwerken.');
            }
            if($group['preview']!==null){
                try{(new InvoicePayload())->build($group['preview'],date('Y-m-d'));}
                catch(InvoiceException $e){$group['confirmable']=false;$group['prepare_issue']=$e->getMessage();}
            }
            if ($overview['seconds'] > PHP_INT_MAX - $group['selected_seconds']) { throw new PreviewException('Geselecteerde urensom is te groot voor een veilige integerberekening.'); }
            $overview['seconds'] += $group['selected_seconds'];
            foreach ($group['entries'] as &$entry) {
                $entry['selectable'] = $this->allowed($entry, $context, $filter);
                $entry['selected'] = in_array((string) $entry['id'], $result['selection'][$entry['project_id']] ?? [], true);
            }
            unset($entry);
        }
        unset($group);
        return ['emptyMessage'=>\App\Toggl\ProjectChoice::emptyMessage($hours), 'overview' => $overview, 'projectOptions' => $options, 'state' => ['selection' => $result['selection'], 'modes' => $result['modes']]];
    }

    private function context(): array
    {
        $result = [];
        foreach ($this->projects->overview()['projects'] as $project) { $result[$project['toggl_project_id']] = $project; }
        return $result;
    }

    private function buildWithContext(array $hours, mixed $selection, mixed $modes, array $context): array
    {
        if (!is_array($selection) || !is_array($modes)) { throw new PreviewException('Ongeldige selectie of factuurregelmodus.'); }
        $dataset = [];
        foreach ($hours['entries'] as $entry) {
            if (isset($dataset[$entry['id']])) { throw new PreviewException('Dubbel entry-ID in de opgehaalde dataset. Haal uren opnieuw op.'); }
            $dataset[$entry['id']] = $entry;
        }
        $filter = $hours['project_filter'] ?? '';
        $cleanModes = [];
        foreach ($modes as $projectId => $mode) {
            $id = $this->id($projectId);
            if (!isset($context[$id]) || $this->issue($context[$id]) !== null || !is_string($mode) || !in_array($mode, ['summary', 'description'], true)) {
                throw new PreviewException('Ongeldige of niet beschikbare projectmodus. Controleer de projectconfiguratie.');
            }
            if (!array_filter($hours['entries'], static fn ($entry) => $entry['project_id'] === $id) || ($filter !== '' && $filter !== (string) $id)) {
                throw new PreviewException('Project behoort niet tot de huidige uren-/projectselectie.');
            }
            $cleanModes[$id] = $mode;
        }
        $previews = [];
        $cleanSelection = [];
        foreach ($selection as $projectId => $ids) {
            $id = $this->id($projectId);
            if (!is_array($ids) || !array_is_list($ids) || !isset($context[$id]) || $this->issue($context[$id]) !== null) {
                throw new PreviewException('Projectconfiguratie ontbreekt of is niet meer geldig. Controleer Projectkoppelingen.');
            }
            if (!array_filter($hours['entries'], static fn ($entry) => $entry['project_id'] === $id) || ($filter !== '' && $filter !== (string) $id)) {
                throw new PreviewException('Project behoort niet tot de huidige uren-/projectselectie.');
            }
            $entries = [];
            foreach ($ids as $rawId) {
                $entryId = $this->id($rawId);
                $entry = $dataset[$entryId] ?? null;
                if ($entry === null || $entry['project_id'] !== $id || !$this->allowed($entry, $context, $filter) || isset($entries[$entryId])) {
                    throw new PreviewException('Ongeldig, dubbel of niet factureerbaar entry-ID in de huidige projectselectie.');
                }
                $entries[$entryId] = $entry;
            }
            $mode = $cleanModes[$id] ?? 'summary';
            $cleanModes[$id] = $mode;
            $cleanSelection[$id] = array_map('strval', array_keys($entries));
            if ($entries === []) { continue; }
            // Deterministic first original description and stable line allocation, independent of POST order.
            usort($entries, static fn ($a, $b) => (new DateTimeImmutable($a['start'])) <=> (new DateTimeImmutable($b['start'])) ?: $a['id'] <=> $b['id']);
            $project = $context[$id];
            $rate = $project['mapping']['hourly_rate_cents'];
            $calculated = (new LineCalculator())->calculate($entries, $rate, $mode, $project['name']);
            $previews[$id] = $calculated + [
                'toggl_project_id' => $id, 'project_name' => $project['name'],
                'wefact_debtor_id' => $project['debtor']['wefact_debtor_id'],
                'wefact_display_name' => $project['debtor']['display_name'], 'debtor_number' => $project['debtor']['debtor_number'],
                'hourly_rate_cents' => $rate, 'entry_ids' => array_column($entries, 'id'), 'mode' => $mode,
                'from' => $hours['from'], 'to' => $hours['to'], 'complete' => $hours['complete'],
            ];
        }
        return ['previews' => $previews, 'selection' => $cleanSelection, 'modes' => $cleanModes];
    }

    private function allowed(array $entry, array $context, string $filter): bool
    {
        $project = $context[$entry['project_id']] ?? null;
        return TimeEntryStatus::isBillable($entry) && $this->issue($project) === null &&
            $entry['workspace_id'] === $project['toggl_workspace_id'] && ($filter === '' || $filter === (string) $entry['project_id']);
    }

    private function issue(?array $project): ?string
    {
        return $this->projects->configurationIssue($project);
    }

    private function id(mixed $value): int
    {
        if (is_int($value) && $value > 0) { return $value; }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) { return (int) $value; }
        throw new PreviewException('Ongeldig entry- of project-ID.');
    }
}
