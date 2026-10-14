<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Toggl\ProjectChoice;
function dailyCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$projects=[['id'=>1,'name'=>'Actief','active'=>true],['id'=>2,'name'=>'Archief','active'=>false],['id'=>3,'name'=>'Oud geselecteerd','active'=>false]];
$entry=['id'=>1,'project_id'=>2,'tags'=>['invoiced'],'duration'=>900,'stop'=>'2026-10-10T12:00:00Z'];
dailyCheck(array_column(ProjectChoice::options([],$projects),'id')===['1'],'Standaard toont archief');
dailyCheck(count(ProjectChoice::options([],$projects,true))===3,'Archiefoptie toont niet alle projecten');
dailyCheck(in_array('2',array_column(ProjectChoice::options([$entry],$projects),'id'),true),'Periodeproject verdwijnt door archief of invoiced');
dailyCheck(in_array('3',array_column(ProjectChoice::options([],$projects,false,'3'),'id'),true),'Geselecteerd archiefproject verdwijnt');
dailyCheck(in_array('99',array_column(ProjectChoice::options([],$projects,false,'99'),'id'),true),'Onbekend geselecteerd project verdwijnt');
dailyCheck(str_contains(ProjectChoice::emptyMessage(['entries'=>[],'only_uninvoiced'=>true]),'Geen uren gevonden'),'Geen-uren-state fout');
dailyCheck(str_contains(ProjectChoice::emptyMessage(['entries'=>[$entry],'only_uninvoiced'=>true]),'al gefactureerd'),'Alle gefactureerd-state fout');
dailyCheck(str_contains(ProjectChoice::emptyMessage(['entries'=>[$entry],'only_uninvoiced'=>true,'project_filter'=>'1']),'filters'),'Filter-state fout');
$entry['tags']=[];$entry['locally_invoiced']=true;dailyCheck(str_contains(ProjectChoice::emptyMessage(['entries'=>[$entry],'only_uninvoiced'=>true]),'al gefactureerd'),'Lokale factuurstatus niet uitgelegd');
// Read-only project catalog metadata is used even before a period is loaded.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new App\Database\Migrator($pdo,dirname(__DIR__).'/database/migrations'))->migrate();
$repo=new App\Project\ProjectRepository($pdo);$repo->synchronize([['id'=>1,'workspace_id'=>100,'name'=>'Actief','active'=>true],['id'=>2,'workspace_id'=>100,'name'=>'Archief','active'=>false]],'token');
$service=new App\Project\ProjectService($repo,new App\Project\MappingRepository($pdo),new App\WeFact\DebtorCache($pdo),'token','key');
$preview=new App\Invoice\PreviewService($service);
dailyCheck(array_column($preview->projectChoices([],[]),'id')===['1'],'DB active-status niet gebruikt vóór urenopvraag');
$h=['entries'=>[['id'=>1,'project_id'=>1,'workspace_id'=>100,'duration'=>900,'description'=>'Werk','start'=>'2026-10-10T10:00:00Z','stop'=>'2026-10-10T10:15:00Z','tags'=>[]]],'only_uninvoiced'=>true,'complete'=>true,'project_filter'=>'','from'=>'2026-10-10','to'=>'2026-10-10'];
$display=$preview->presentation($h,[],new DateTimeZone('Europe/Amsterdam'));
dailyCheck(str_contains($display['overview']['groups'][0]['issue'],'niet gekoppeld'),'Ongekoppeld project wordt niet uitgelegd');
dailyCheck(in_array('1',array_column($display['projectOptions'],'id'),true),'Mappingstatus verbergt projectkeuze');
ob_start();(new App\View\View(dirname(__DIR__).'/templates'))->page('Test','Start','Kies uren','/');$html=ob_get_clean();dailyCheck(str_contains($html,'Uren ophalen')&&!str_contains($html,'niet geïmplementeerd'),'Homepage verouderd');
echo "Dagelijkse UX-tests geslaagd: actief/archief/periode/selectie, invoiced-onafhankelijke projectkeuze, lege states en homepage.\n";
