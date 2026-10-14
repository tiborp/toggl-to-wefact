<?php
declare(strict_types=1);
namespace App\Toggl;

/** Builds local project choices independently of mapping and invoice visibility. */
final class ProjectChoice
{
    public static function visible(bool $active,bool $showArchived,bool $required=false):bool
    {
        return $active||$showArchived||$required;
    }
    public static function options(array $entries,array $projects,bool $archived=false,string $selected=''):array
    {
        $required=[];foreach($entries as$entry){$required[$entry['project_id']===null?'none':(string)$entry['project_id']]=true;}
        $options=TimeEntryOverview::projectOptions($entries,$projects);
        if($selected!==''&&!in_array($selected,array_column($options,'id'),true)){$options[]=['id'=>$selected,'name'=>$selected==='none'?'Geen project':'Onbekend project (ID '.$selected.')'];}
        $active=[];
        foreach($projects as$p){$active[(string)$p['id']]=(bool)($p['active']??true);}
        foreach($options as&$option){$option['archived']=isset($active[$option['id']])&&!$active[$option['id']];}unset($option);
        return array_values(array_filter($options,static fn($o)=>self::visible(!$o['archived'],$archived,isset($required[$o['id']])||$o['id']===$selected)));
    }
    public static function emptyMessage(array $hours):string
    {
        if (!($hours['complete'] ?? true)) {
            return $hours['entries'] === []
                ? 'Er zijn geen uren beschikbaar uit deze onvolledige opvraag. Of er uren in de periode zijn, is niet vastgesteld.'
                : 'De opgehaalde subset bevat geen uren voor de gekozen filters. Door de onvolledige opvraag is niet vastgesteld of dit voor de hele periode geldt.';
        }
        if($hours['entries']===[]){return 'Geen uren gevonden in de gekozen periode.';}
        $filtered=array_filter($hours['entries'],static fn($e)=>($hours['project_filter']??'')===''||($e['project_id']===null?'none':(string)$e['project_id'])===$hours['project_filter']);
        if($filtered!==[]&&$hours['only_uninvoiced']&&count(array_filter($filtered,[TimeEntryStatus::class,'isInvoiced']))===count($filtered)){return 'Alle gevonden uren zijn al gefactureerd. Schakel Alleen niet-gefactureerde uren uit om ze te bekijken.';}
        return 'De gekozen filters leveren geen uren op. Kies een ander project of pas de filters aan.';
    }
}
