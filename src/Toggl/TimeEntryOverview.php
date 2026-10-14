<?php
declare(strict_types=1);
namespace App\Toggl;

use DateTimeImmutable;
use DateTimeZone;

/** Groups fetched entries for display while excluding running and invoiced time from billable totals. */
final class TimeEntryOverview
{
    /** Navigation uses available Toggl data, never invoice visibility or mapping status. */
    public static function projectOptions(array $entries,array $projects): array
    {
        $options=[];
        foreach($projects as$project){$id=(string)$project['id'];$options[$id]=['id'=>$id,'name'=>$project['name']];}
        foreach($entries as$entry){
            $id=$entry['project_id']===null?'none':(string)$entry['project_id'];
            $options[$id]??=['id'=>$id,'name'=>$id==='none'?'Geen project':'Onbekend project (ID '.$id.')'];
        }
        uasort($options,static fn($a,$b)=>strnatcasecmp($a['name'],$b['name']) ?: strcmp($a['id'],$b['id']));
        return array_values($options);
    }

    public function build(array $entries, array $projects, bool $onlyUninvoiced, DateTimeZone $timezone): array
    {
        $names = [];
        foreach ($projects as $project) { $names[$project['id']] = $project['name']; }
        $groups = [];
        $total = 0;
        foreach ($entries as $entry) {
            $sources = TimeEntryStatus::invoiceSources($entry);
            $invoiced = $sources !== [];
            if ($onlyUninvoiced && $invoiced) { continue; }
            $projectId = $entry['project_id'];
            $key = $projectId === null ? 'none' : (string) $projectId;
            $groups[$key] ??= [
                'project_id' => $projectId,
                'name' => $projectId === null ? 'Geen project' : ($names[$projectId] ?? 'Onbekend project (ID ' . $projectId . ')'),
                'entries' => [], 'seconds' => 0,
            ];
            $running = TimeEntryStatus::isRunning($entry);
            $billable = TimeEntryStatus::isBillable($entry);
            $entry['date'] = (new DateTimeImmutable($entry['start']))->setTimezone($timezone)->format('d-m-Y H:i');
            $entry['running'] = $running;
            $entry['invoiced'] = $invoiced;
            $entry['invoice_sources'] = $sources;
            $entry['billable'] = $billable;
            $groups[$key]['entries'][] = $entry;
            if ($billable) {
                $groups[$key]['seconds'] += $entry['duration'];
                $total += $entry['duration'];
            }
        }
        foreach ($groups as &$group) {
            usort($group['entries'], static fn ($a, $b) => (new DateTimeImmutable($a['start'])) <=> (new DateTimeImmutable($b['start'])) ?: $a['id'] <=> $b['id']);
        }
        unset($group);
        uasort($groups, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return ['groups' => array_values($groups), 'seconds' => $total];
    }

    public static function duration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainder = $seconds % 60;
        return sprintf('%d:%02d', $hours, $minutes) . ($remainder ? sprintf(':%02d', $remainder) : '');
    }
}
