<?php
declare(strict_types=1);

use App\Invoice\{PreviewService, PreviewController, PreviewException, LineCalculator};
use App\Project\{ProjectRepository, MappingRepository, ProjectService};
use App\WeFact\DebtorCache;
use App\Toggl\{TogglSession, HttpTransport, HttpResponse, TimeEntryController};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
require dirname(__DIR__) . '/vendor/autoload.php';
function checkPreview(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function rejectedPreview(callable $call): void {
    try { $call(); } catch (PreviewException) { return; }
    throw new RuntimeException('Ongeldige preview geaccepteerd');
}
function previewEntry(int $id, int $project, int $seconds, string $description, array $tags = [], ?string $stop = '2026-10-10T12:00:00Z'): array {
    return ['id'=>$id,'project_id'=>$project,'workspace_id'=>100,'duration'=>$seconds,'description'=>$description,'tags'=>$tags,'start'=>'2026-10-10T10:00:00Z','stop'=>$stop];
}
final class PreviewHttp implements HttpTransport {
    public int $calls = 0;
    public function __construct(private array $entries) {}
    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse {
        $this->calls++;
        checkPreview(str_starts_with($path, '/me/time_entries?'), 'Onverwachte API-call');
        return new HttpResponse(200, json_encode($this->entries, JSON_THROW_ON_ERROR));
    }
}
checkPreview(PreviewController::redirectLocation('42',[42=>'summary'])==='/factuur-preview#preview-project-42','Projectanchor fout');
foreach([null,'','999',[],"42\r\nLocation: https://invalid.test",'https://invalid.test'] as $target){
    checkPreview(PreviewController::redirectLocation($target,[42=>'summary'])==='/factuur-preview#factuur-preview','Onveilig of onbekend scrolltarget toegelaten');
}
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new Migrator($pdo,dirname(__DIR__).'/database/migrations'))->migrate();
$tf = hash('sha256','stub-token'); $wf = hash('sha256','stub-key');
$repo = new ProjectRepository($pdo); $maps = new MappingRepository($pdo); $cache = new DebtorCache($pdo);
$repo->synchronize(array_map(fn ($id) => ['id'=>$id,'name'=>$id===1?'<script>Project A':'Project '.$id,'workspace_id'=>100,'active'=>true],range(1,5)),$tf);
$cache->replace([['wefact_debtor_id'=>'42','debtor_number'=>'DB42','display_name'=>'<script>Klant','company_name'=>'','first_name'=>'','last_name'=>'','city'=>'','email'=>'']],$wf);
$projects = new ProjectService($repo,$maps,$cache,$tf,$wf);
$projects->save('1','42','85'); $projects->save('2','42','95'); $projects->save('4','42','');
$preview = new PreviewService($projects);
$entries = [previewEntry(1,1,7200,'Websitewerk'),previewEntry(2,1,1800,'Overleg'),previewEntry(3,1,4500,'Websitewerk'),previewEntry(4,1,2700,'Correcties'),previewEntry(5,1,2700,' overleg '),
    previewEntry(6,1,-1,'Running',[],null),previewEntry(7,1,900,'Done',['INVOICED']),previewEntry(8,1,900,'Done',['billed BY factuursturen.NL']),
    previewEntry(9,2,3600,'<script>Other'),previewEntry(10,3,3600,'Unconfigured'),previewEntry(11,4,3600,'No rate')];
$hours = ['entries'=>$entries,'from'=>'2026-10-07','to'=>'2026-10-13','only_uninvoiced'=>true,'complete'=>true,'warning'=>null,'project_warning'=>null,'dataset_id'=>'dataset-one','loaded_at'=>'2026-10-13T10:35:30+02:00','project_filter'=>''];
$display = $preview->presentation($hours,[],new DateTimeZone('Europe/Amsterdam'));
$selected = $display['state']['selection'];
checkPreview($selected[1] === ['1','2','3','4','5'] && $selected[2] === ['9'] && count($selected)===2,'Standaardselectie bevat ongeldige entries of ontbreekt');
$models = $preview->build($hours,$selected,[])['previews'];
checkPreview(count($models)===2 && $models[1]['wefact_debtor_id']===$models[2]['wefact_debtor_id'],'Projecten zelfde debtor samengevoegd');
checkPreview($models[1]['toggl_project_id']===1 && $models[2]['toggl_project_id']===2 && count($models[1]['lines'])===1,'Eén project/summary onjuist');
checkPreview($models[1]['duration_seconds']===18900 && $models[1]['total_cents']===44625 && $models[2]['total_cents']===9500,'Seconden/tarieven tussen projecten vermengd');
$description = $preview->build($hours,$selected,[1=>'description',2=>'summary'])['previews'][1];
checkPreview(count($description['lines'])===3 && $description['total_cents']===$models[1]['total_cents'],'Per omschrijving/totalen onjuist');
$lineByName = []; foreach ($description['lines'] as $line) { $lineByName[$line['description']]=$line; }
checkPreview($lineByName['Overleg']['duration_seconds']===4500 && $lineByName['Overleg']['entry_ids']===[2,5],'Trim/case/onderliggende IDs niet behouden');
checkPreview($lineByName['Websitewerk']['duration_seconds']===11700 && $lineByName['Websitewerk']['entry_ids']===[1,3],'Descriptiongroepering fout');
$deselected = $preview->build($hours,[1=>['1','2','3','5']],[])['previews'][1];
checkPreview($deselected['duration_seconds']===16200 && $deselected['total_cents']===38250,'Deselection werkt niet');
checkPreview($preview->build($hours,[],[])['previews']===[],'Deselect-all levert nog preview');
checkPreview(count($preview->build($hours,[1=>$selected[1]],[])['previews'])===1,'Projectselectie/deselect-all ander project onjuist');
foreach ([6,7,8,999] as $id) { rejectedPreview(fn () => $preview->build($hours,[1=>[(string)$id]],[])); }
rejectedPreview(fn () => $preview->build($hours,[1=>['9']],[]));
rejectedPreview(fn () => $preview->build($hours,[1=>['1','1']],[]));
rejectedPreview(fn () => $preview->build($hours,[3=>['10']],[]));
rejectedPreview(fn () => $preview->build($hours,[4=>['11']],[]));
rejectedPreview(fn () => $preview->build($hours,[1=>['1']],[1=>'evil']));
$filteredHours=$hours; $filteredHours['project_filter']='2';
rejectedPreview(fn () => $preview->build($filteredHours,[1=>['1']],[]));
$filtered=$preview->presentation($filteredHours,[],new DateTimeZone('UTC'));
checkPreview(count($filtered['overview']['groups'])===1 && array_keys($filtered['state']['selection'])===[2],'Projectfilter niet leidend');
$issues=[]; foreach ($display['overview']['groups'] as $group) { $issues[$group['project_id']]=$group['issue']; }
checkPreview(str_contains($issues[3],'niet gekoppeld') && str_contains($issues[4],'Uurtarief'),'Blokkades niet duidelijk getoond');
$wrongWorkspace=$hours; $wrongWorkspace['entries'][0]['workspace_id']=999;
rejectedPreview(fn () => $preview->build($wrongWorkspace,[1=>['1']],[]));

// Navigation options are independent of hidden invoice entries and mapping availability.
$invoicedHours=$hours;$invoicedHours['entries']=[];
for($id=1001;$id<=1025;$id++){$invoicedHours['entries'][]=previewEntry($id,1,900,'Example Project',['invoiced']);}
$taggedDisplay=$preview->presentation($invoicedHours,[1=>['id'=>1,'name'=>'Example Project'],99=>['id'=>99,'name'=>'Catalog only']],new DateTimeZone('Europe/Amsterdam'));
checkPreview($taggedDisplay['overview']['groups']===[]&&$taggedDisplay['overview']['seconds']===0,'Getagde entries opnieuw zichtbaar/factureerbaar');
checkPreview(in_array('1',array_column($taggedDisplay['projectOptions'],'id'),true)&&in_array('99',array_column($taggedDisplay['projectOptions'],'id'),true),'Projectlijst verdwijnt na 25 invoiced-tags');
$invoicedHours['project_filter']='1';
checkPreview(count($preview->presentation($invoicedHours,[99=>['id'=>99,'name'=>'Catalog only']],new DateTimeZone('UTC'))['projectOptions'])>=2,'Projectfilter verwijdert andere opties');
$missingNames=\App\Toggl\TimeEntryOverview::projectOptions([previewEntry(1,500,60,'No mapping'),array_replace(previewEntry(2,1,60,'No project'),['project_id'=>null])],[]);
checkPreview(in_array('500',array_column($missingNames,'id'),true)&&in_array('none',array_column($missingNames,'id'),true),'Ontbrekende mapping/projectnaam maakt dropdown leeg');

$calculator = new LineCalculator();
$descs=[previewEntry(20,1,1,'Overleg'),previewEntry(21,1,1,'overleg'),previewEntry(22,1,1,' Overleg '),previewEntry(23,1,1,'Overleg klant'),previewEntry(24,1,1,'')];
$calc=$calculator->calculate($descs,1800,'description','A');
checkPreview(count($calc['lines'])===3 && $calc['lines'][0]['duration_seconds']===3 && $calc['lines'][2]['description']==='Werkzaamheden','Normalisatie/fallback/fuzzy matching');
checkPreview($calculator->calculate([previewEntry(1,1,1,'a')],1800,'summary','A')['total_cents']===450,'Halve cent niet omhoog afgerond');
checkPreview($calculator->calculate([previewEntry(1,1,1,'a')],1799,'summary','A')['total_cents']===450,'Minder dan halve cent fout');
// Individually rounded lines would yield two cents; project total must remain one cent.
$tiny=[previewEntry(1,1,1,'a'),previewEntry(2,1,1,'b')];
$summary=$calculator->calculate($tiny,1800,'summary','A');$grouped=$calculator->calculate($tiny,1800,'description','A');
checkPreview($summary['total_cents']===450 && $grouped['total_cents']===450 && array_sum(array_column($grouped['lines'],'amount_cents'))===450,'Modus-afhankelijke centafronding');
checkPreview($grouped['lines'][0]['amount_cents']===450 && $grouped['lines'][1]['amount_cents']===0,'Deterministische restcentverdeling');
for ($i=1;$i<=150;$i++) {
    $sample=[previewEntry(1,1,$i,'a'),previewEntry(2,1,$i*7+1,'b'),previewEntry(3,1,$i*2+3,'c')];
    $a=$calculator->calculate($sample,8500,'summary','A'); $b=$calculator->calculate($sample,8500,'description','A');
    checkPreview(is_int($a['total_cents']) && $a['total_cents']===$b['total_cents'] && $b['total_cents']===array_sum(array_column($b['lines'],'amount_cents')),'Integer/mode invariant fout');
}
rejectedPreview(fn () => $calculator->calculate([previewEntry(1,1,PHP_INT_MAX,'A')],8500,'summary','A'));
// Show an unrepresentable fractional-cent split directly in the preview.
$projects->save('1','42','85.02');
$centHours=$hours;$centHours['entries']=[previewEntry(201,1,900,'a'),previewEntry(202,1,900,'b')];
$centState=['selection'=>[1=>['201','202']],'modes'=>[1=>'description']];
$centDisplay=$preview->presentation($centHours,[],new DateTimeZone('Europe/Amsterdam'),$centState);
checkPreview(str_contains($centDisplay['overview']['groups'][0]['prepare_issue'],'Samengevat'),'Restcentbeperking ontbreekt in preview');
// Re-check local configuration on every build, not a saved client/customer/rate snapshot.
$projects->save('1','42','90');
checkPreview($preview->build($hours,[1=>['1']],[])['previews'][1]['hourly_rate_cents']===9000,'Verouderd tarief gebruikt');
$cache->replace([],$wf);
rejectedPreview(fn () => $preview->build($hours,[1=>['1']],[]));
$displayMissing=$preview->presentation($hours,[],new DateTimeZone('UTC'));
checkPreview(str_contains($displayMissing['overview']['groups'][0]['issue'],'debiteur ontbreekt'),'Ontbrekende debtor niet gemeld');
$cache->replace([['wefact_debtor_id'=>'42','debtor_number'=>'DB42','display_name'=>'<script>Klant','company_name'=>'','first_name'=>'','last_name'=>'','city'=>'','email'=>'']],$wf);

// Inactive/missing project, projectless entry and changed tags are also rejected.
$repo->synchronize([['id'=>1,'name'=>'A','workspace_id'=>100,'active'=>false],['id'=>2,'name'=>'B','workspace_id'=>100,'active'=>true]],$tf);
rejectedPreview(fn () => $preview->build($hours,[1=>['1']],[]));
$repo->synchronize(array_map(fn ($id) => ['id'=>$id,'name'=>$id===1?'<script>Project A':'Project '.$id,'workspace_id'=>100,'active'=>true],range(1,5)),$tf);
$projectless=$hours;$orphan=previewEntry(99,1,60,'No project');$orphan['project_id']=null;$projectless['entries'][]=$orphan;
$displayOrphan=$preview->presentation($projectless,[],new DateTimeZone('UTC'));
$orphans=array_filter($displayOrphan['overview']['groups'],fn($group)=>$group['project_id']===null);
checkPreview(count($orphans)===1 && !array_values($orphans)[0]['entries'][0]['selectable'],'Projectloze entry selecteerbaar/verstopt');
$changedTags=$hours;$changedTags['entries'][0]['tags']=['INVOICED'];
rejectedPreview(fn () => $preview->build($changedTags,[1=>['1']],[]));
$duplicateDataset=$hours;$duplicateDataset['entries'][]=$duplicateDataset['entries'][0];
rejectedPreview(fn () => $preview->build($duplicateDataset,[1=>['1']],[]));
$emptyModel=$preview->build($hours,[1=>[]],[1=>'description']);
checkPreview($emptyModel['previews']===[],'Lege selectie maakt nul-factuur');

// Exact seconds, both individual and grouped, with no duration aliases.
foreach ([60,360,660,3780] as $seconds) {
    $source=[previewEntry(101,1,$seconds,'Werk')];
    $exact=$calculator->calculate($source,8500,'summary','A');
    checkPreview($exact['duration_seconds']===$seconds && $exact['entries'][0]['duration']===$seconds,'Tijd is afgerond: '.$seconds);
    checkPreview(!array_key_exists('billable_duration_seconds',$exact) && !array_key_exists('actual_duration_seconds',$exact),'Dubbele durationvelden blijven aanwezig');
}
$short=[previewEntry(101,1,60,'Overleg'),previewEntry(102,1,360,' overleg '),previewEntry(103,1,11,'Werk')];
$before=$short;
$a=$calculator->calculate($short,8500,'summary','A');$b=$calculator->calculate($short,8500,'description','A');
checkPreview($short===$before,'Oorspronkelijke Toggl-duration gewijzigd');
checkPreview($a['duration_seconds']===431 && $b['duration_seconds']===431,'Korte entries niet exact opgeteld');
checkPreview($b['lines'][0]['duration_seconds']===420,'Groepering rondt korte entries af');
checkPreview($a['total_cents']===$b['total_cents'],'Regelmodi hebben verschillend totaal');
$exactHours=$hours;$exactHours['entries']=$short;
$exactDisplay=$preview->presentation($exactHours,[],new DateTimeZone('Europe/Amsterdam'));
checkPreview($exactDisplay['overview']['seconds']===900,'Selectietotaal niet exact');
$one=$preview->build($exactHours,[1=>['102']],[])['previews'][1];
checkPreview($one['duration_seconds']===360,'Deselectie verandert tijd');

// Visible dates follow APP_TIMEZONE without changing internal API/session values.
$oldTimezone=date_default_timezone_get();
try {
    date_default_timezone_set('Europe/Amsterdam');
    checkPreview(\App\View\DutchDate::date('2026-09-16')==='16-09-2026','Nederlandse datum fout');
    checkPreview(\App\View\DutchDate::dateTime('2026-10-13T08:35:30Z')==='13-10-2026 10:35','APP_TIMEZONE niet toegepast');
    checkPreview(\App\View\DutchDate::dateTime('2026-01-13T09:35:30Z')==='13-01-2026 10:35','Wintertijd niet toegepast');
    checkPreview(\App\View\DutchDate::dateTime('2026-10-13T22:35:30Z')==='14-10-2026 00:35','Datumgrens/timezone fout');
    $formatHours=$exactHours;$formatHours['from']='2026-09-16';
    ob_start();
    $data=['hours'=>$formatHours,'overview'=>$exactDisplay['overview'],'projectOptions'=>[], 'quotas'=>[],'csrf'=>'test','error'=>null,'from'=>$formatHours['from'],'to'=>$formatHours['to'],'onlyUninvoiced'=>true];
    (new View(dirname(__DIR__).'/templates'))->page('Test','Preview','','/factuur-preview',$data);
    $formattedHtml=ob_get_clean();
    checkPreview(str_contains($formattedHtml,'Resultaat voor 16-09-2026 t/m 13-10-2026 · opgehaald 13-10-2026 10:35.'),'Zichtbare resultaatdatum onjuist');
    checkPreview(str_contains($formattedHtml,'Periode: 07-10-2026 – 13-10-2026'),'Previewperiode niet Nederlands');
    checkPreview(str_contains($formattedHtml,'<th>Duur</th>') && str_contains($formattedHtml,'eenmaal naar boven afgerond'),'Verschil in duur niet zichtbaar');
    $dom=new DOMDocument();@$dom->loadHTML($formattedHtml);$xpath=new DOMXPath($dom);
    checkPreview($xpath->query('//*[@id="factuur-preview"]')->length===1&&$xpath->query('//*[@id="preview-project-1"]')->length===1,'Stabiele anchors ontbreken');
    checkPreview($xpath->query('//form[@id="preview-selection" and @method="post" and @action="/factuur-preview#factuur-preview"]')->length===1,'Progressieve POST-fallback ontbreekt');
    checkPreview($xpath->query('//input[@type="radio" and @data-preview-project="1" and @name="modes[1]"]')->length===2,'Indeling mist auto-submit marker');
    checkPreview($xpath->query('//form[@id="preview-selection"]//input[@name="csrf"]')->length===1&&$xpath->query('//form[@id="preview-selection"]//input[@name="dataset_id"]')->length===1,'Bestaande beveiligingsvelden ontbreken');
    checkPreview(str_contains($formattedHtml,'value="2026-09-16"') && $formatHours['from']==='2026-09-16','Date-input/interne datum aangepast');
} finally {date_default_timezone_set($oldTimezone);}

$temp=sys_get_temp_dir().'/preview-config-'.bin2hex(random_bytes(8));mkdir($temp);file_put_contents($temp.'/.env',"TOGGL_API_TOKEN=stub-token\nWEFACT_API_KEY=stub-key\n");
$config=new Config($temp);$view=new View(dirname(__DIR__).'/templates');$controller=new PreviewController($config,$view,$preview);
try {
    TogglSession::open($config);$_SESSION['toggl_hours']=$hours;$csrf=$_SESSION['csrf'];$sessionId=session_id();session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>'wrong','dataset_id'=>'dataset-one','selected'=>[1=>['1']]];
    ob_start();$controller->handle();ob_end_clean();checkPreview(http_response_code()===403,'CSRF niet geweigerd');
    $_POST=['csrf'=>$csrf,'dataset_id'=>'old-dataset','selected'=>[1=>['1']]];
    ob_start();$controller->handle();ob_end_clean();checkPreview(http_response_code()===400,'Oud formulier geaccepteerd');
    $_POST=['csrf'=>$csrf,'dataset_id'=>'dataset-one','selected'=>[1=>['999']]];
    ob_start();$controller->handle();ob_end_clean();checkPreview(http_response_code()===400,'Gemanipuleerd ID geaccepteerd');
    $_POST=['csrf'=>$csrf,'dataset_id'=>'dataset-one','selected'=>[1=>['1']], 'modes'=>[1=>'summary'],'duration'=>1,'rate'=>1,'wefact_debtor_id'=>'evil','total_cents'=>1];
    ob_start();$controller->handle();$out=ob_get_clean();checkPreview(http_response_code()===303 && $out==='','POST/redirect ontbreekt');
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    ob_start();$controller->handle();$html=ob_get_clean();
    checkPreview(str_contains($html,'€ 180,00') && str_contains($html,'€ 90,00') && !str_contains($html,'evil'),'Geposte financiële gegevens vertrouwd');
    checkPreview(!str_contains($html,'<script>Project') && str_contains($html,'&lt;script&gt;Project'),'HTML escaping ontbreekt');
    $repo->synchronize([['id'=>1,'name'=>'A','workspace_id'=>100,'active'=>false],['id'=>2,'name'=>'B','workspace_id'=>100,'active'=>true]],$tf);
    ob_start();$controller->handle();$blockedHtml=ob_get_clean();
    checkPreview(str_contains($blockedHtml,'Niet factureerbaar:') && str_contains($blockedHtml,'Project is niet actief/beschikbaar.'),'Ongeldige configuratie verbergt de uren');
    $repo->synchronize(array_map(fn ($id) => ['id'=>$id,'name'=>$id===1?'<script>Project A':'Project '.$id,'workspace_id'=>100,'active'=>true],range(1,5)),$tf);
    // Use existing retrieval, then previews without another Toggl/WeFact request.
    $http=new PreviewHttp($entries);
    $hoursController=new TimeEntryController($config,$view,$http,$preview);
    TogglSession::open($config);$_SESSION['toggl_projects']=[1=>['id'=>1,'name'=>'A'],2=>['id'=>2,'name'=>'B'],3=>['id'=>3,'name'=>'C'],4=>['id'=>4,'name'=>'D']];session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'from'=>'2026-10-07','to'=>'2026-10-13','only_uninvoiced'=>'1','action'=>'fetch'];
    ob_start();$hoursController->handle();ob_end_clean();checkPreview($http->calls===1,'Bestaande retrieval niet hergebruikt');
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    ob_start();$hoursController->handle();$html=ob_get_clean();
    checkPreview($http->calls===1 && str_contains($html,'Factuur-preview ·') && str_contains($html,'data-select-project="all"'),'GET/defaultselectie/JS-controls ontbreken');
    ob_start();$controller->handle();ob_end_clean();checkPreview($http->calls===1,'Preview GET doet extra API-call');
    TogglSession::open($config);$_SESSION['toggl_hours']=$invoicedHours;unset($_SESSION['invoice_preview']);session_write_close();
    ob_start();$hoursController->handle();$taggedHtml=ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML($taggedHtml);$xp=new DOMXPath($dom);
    checkPreview($xp->query('//select[@id="project-filter"]/option[@value="1"]')->length===1&&$xp->query('//select[@id="project-filter"]/option[@value="2"]')->length===1,'Uren-dropdown leeg na synchronisatie');
    checkPreview($xp->query('//input[@data-entry-selection]')->length===0&&$http->calls===1,'Getagde entries selecteerbaar of extra API-call');

} finally {
    if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}
    if(isset($sessionId)){$file=dirname(__DIR__).'/storage/sess_'.$sessionId;if(is_file($file)){unlink($file);}}
    unlink($temp.'/.env');rmdir($temp);
}
echo "Preview-tests geslaagd: project-isolatie, selectie, configuratie/tags, omschrijvingen/IDs, integer-afronding, modus-invariant, manipulatie, CSRF, escaping en lokale GET.\n";
