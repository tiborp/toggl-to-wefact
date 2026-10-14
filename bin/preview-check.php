<?php
declare(strict_types=1);

use App\Toggl\{DateRange,TogglClient,CurlTransport,Quota,TimeEntryReader,TogglException};
use App\Project\{ProjectService,ProjectRepository,MappingRepository};
use App\WeFact\DebtorCache;
use App\Invoice\{PreviewService,PreviewException};
$app = require dirname(__DIR__) . '/bootstrap.php';
$config=$app['config'];
if ($config->togglToken()==='') { echo "Geen Toggl-token; echte API niet benaderd.\n"; exit(0); }
try {
    $tz=new DateTimeZone($config->get('APP_TIMEZONE','Europe/Amsterdam'));
    $range=DateRange::fromInput($argv[1]??null,$argv[2]??null,$tz);
    $result=(new TimeEntryReader(new TogglClient($config->togglToken(),new CurlTransport(),new Quota())))->read($range);
    $hours=$result+['from'=>$range->from,'to'=>$range->to,'only_uninvoiced'=>true,'project_filter'=>''];
    $projects=new ProjectService(new ProjectRepository($app['pdo']),new MappingRepository($app['pdo']),new DebtorCache($app['pdo']),hash('sha256',$config->togglToken()),hash('sha256',$config->wefactKey()));
    $display=(new PreviewService($projects))->presentation($hours,[],$tz);
    $count=0;$blocked=0;
    foreach($display['overview']['groups'] as $group){if($group['preview']!==null){$count++;}if($group['issue']!==null){$blocked++;}}
    echo 'Opgehaalde entries: '.count($result['entries'])."\n";
    echo 'Volledige dataset: '.($result['complete']?'ja':'nee')."\n";
    echo 'Succesvolle Toggl-urenrequests: '.$result['requests']."\n";
    echo 'Projecten met echte preview: '.$count."\n";
    echo 'Projectgroepen met ontbrekende/ongeldige configuratie: '.$blocked."\n";
    echo "Alleen bestaande mappings gebruikt; niets gewijzigd in SQLite, Toggl of WeFact.\n";
    if($result['warning']!==null){echo $result['warning']."\n";}
} catch(TogglException|PreviewException $error){echo $error->getMessage()."\n";exit(1);}
