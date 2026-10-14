<?php
declare(strict_types=1);
use App\Invoice\{InvoiceRepository,InvoiceService,InvoicePayload,InvoiceException,ApiDecimal,PreviewService,LocalInvoiceStatus,ConfirmationController,HistoryController,PreviewController};
use App\Project\{ProjectRepository,MappingRepository,ProjectService};
use App\WeFact\{DebtorCache,InvoiceTransport,HttpResponse,WeFactClient};
use App\Toggl\{TimeEntryStatus,TogglSession};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
require dirname(__DIR__).'/vendor/autoload.php';
function invoiceCheck(bool $condition,string $message): void {if(!$condition){throw new RuntimeException($message);}}
function invoiceReject(callable $call): void {try{$call();}catch(Throwable $e){invoiceCheck(!str_contains($e->getMessage(),'stub-secret'),'Secret gelekt');return;}throw new RuntimeException('Verwachte weigering ontbreekt');}
final class InvoiceStub implements InvoiceTransport {
    public array $writes=[];public int $reads=0;
    public function __construct(public ?HttpResponse $response=null){}
    public function listDebtors(#[\SensitiveParameter] string $key,int $offset,int $limit):HttpResponse{$this->reads++;throw new RuntimeException('Ongewenste read');}
    public function addInvoice(#[\SensitiveParameter] string $key,array $payload):HttpResponse {
        $this->writes[]=$payload;
        return $this->response??new HttpResponse(200,'{"controller":"invoice","action":"add","status":"success","invoice":{"Identifier":12,"InvoiceCode":"[concept]12","Status":0,"Debtor":42,"AmountExcl":21.25}}');
    }
}
function invoiceSetup(?PDO $pdo=null): array {
    $pdo??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    (new Migrator($pdo,dirname(__DIR__).'/database/migrations'))->migrate();
    $projects=new ProjectRepository($pdo);$tf=hash('sha256','stub-token');$wf=hash('sha256','stub-secret');
    $projects->synchronize([['id'=>1,'name'=>'A','active'=>true,'workspace_id'=>100],['id'=>2,'name'=>'B','active'=>true,'workspace_id'=>100]],$tf);
    $cache=new DebtorCache($pdo);$cache->replace([['wefact_debtor_id'=>'42','debtor_number'=>'DB42','display_name'=>'Example Client','company_name'=>'Example Client','first_name'=>'','last_name'=>'','city'=>'','email'=>'']],$wf);
    $projectService=new ProjectService($projects,new MappingRepository($pdo),$cache,$tf,$wf);$projectService->save('1','42','85');$projectService->save('2','42','85');
    $preview=new PreviewService($projectService,new LocalInvoiceStatus($pdo));$repo=new InvoiceRepository($pdo);
    $e=['id'=>123,'project_id'=>1,'workspace_id'=>100,'duration'=>900,'start'=>'2026-10-10T10:00:00Z','stop'=>'2026-10-10T10:15:00Z','tags'=>[],'description'=>'Websitewerk'];
    $hours=['dataset_id'=>'one','entries'=>[$e,$e+[]],'from'=>'2026-10-10','to'=>'2026-10-10','complete'=>true,'only_uninvoiced'=>true,'project_filter'=>'','loaded_at'=>'2026-10-10T12:00:00Z','project_warning'=>null];
    $hours['entries'][1]['id']=124;$hours['entries'][1]['project_id']=2;
    $built=$preview->build($hours,[1=>['123'],2=>['124']],[1=>'summary',2=>'description']);
    $state=['dataset_id'=>'one','selection'=>$built['selection'],'modes'=>$built['modes'],'model_hashes'=>array_map([InvoiceRepository::class,'modelHash'],$built['previews'])];
    $http=new InvoiceStub();$fresh=fn($h)=>['entries'=>$h['entries'],'complete'=>true];
    $service=new InvoiceService($preview,$repo,new WeFactClient('stub-secret',$http),$fresh,'source');
    return compact('pdo','projectService','preview','repo','hours','state','http','service','built');
}
foreach([60=>'0.016666666666',360=>'0.100000000000',660=>'0.183333333333',3780=>'1.050000000000',3600=>'1.000000000000',300=>'0.083333333333',600=>'0.166666666666',3900=>'1.083333333333'] as $seconds=>$expected){invoiceCheck(ApiDecimal::hours($seconds)===$expected,'Hours conversie fout');}
invoiceCheck(ApiDecimal::euros(8500)==='85.00' && ApiDecimal::cents('28.3300')===2833,'Prijs/AmountExcl conversie fout');
invoiceReject(fn()=>ApiDecimal::cents('28.331'));invoiceReject(fn()=>ApiDecimal::cents(28.33));
foreach([60=>2125,360=>2125,660=>2125,3780=>10625,3600=>8500,300=>2125,600=>2125,3900=>10625] as $seconds=>$cents){
    $s=invoiceSetup();$h=$s['hours'];$h['entries'][0]['duration']=$seconds;
    $m=$s['preview']->build($h,[1=>['123']],[])['previews'][1];$p=(new InvoicePayload())->build($m,'2026-10-13');
    invoiceCheck(ApiDecimal::roundedLine($p['InvoiceLines'][0]['Number'],8500)===$cents && $m['total_cents']===$cents,'Hours/WeFact bedrag mismatch');
}
$s=invoiceSetup();extract($s);
$payload=(new InvoicePayload())->build($built['previews'][1],'2026-10-13');
invoiceCheck($payload['Status']===0&&$payload['Debtor']===42&&!isset($payload['InvoiceCode']),'Conceptstatus/debiteur/InvoiceCode fout');
invoiceCheck($payload['InvoiceLines'][0]['NumberSuffix']===' uur'&&$payload['InvoiceLines'][0]['PriceExcl']==='85.00'&&!isset($payload['InvoiceLines'][0]['TaxCode']),'Tarief/btw fout');
invoiceCheck($payload['InvoiceLines'][0]['PeriodicType']==='once' && $payload['InvoiceLines'][0]['StartDate']==='2026-10-10','Periode fout');
invoiceCheck($payload['InvoiceLines'][0]['Description']==='Werkzaamheden A','Project ontbreekt in summary');
// Audit the complete wire structure, including transport additions and every nested line.
// No real HTTP request: credentials below are deliberately fake test data.
foreach([$payload,(new InvoicePayload())->build($built['previews'][2],'2026-10-13')] as $auditedPayload){
    $wire=App\WeFact\JsonPayload::encode(['api_key'=>'fake-test-key','controller'=>'invoice','action'=>'add']+$auditedPayload);
    $decoded=json_decode($wire,true,512,JSON_THROW_ON_ERROR);
    $rootKeys=array_keys($decoded);sort($rootKeys);
    $expectedKeys=['api_key','controller','action','Status','Debtor','Date','VatCalcMethod','Description','InvoiceLines'];sort($expectedKeys);
    invoiceCheck($rootKeys===$expectedKeys,'Onverwacht veld in uiteindelijke invoice/add-payload');
    invoiceCheck(!str_contains($wire,'IgnoreDiscount'),'IgnoreDiscount mag niet terugkeren in invoice/add');
    invoiceCheck(str_contains($wire,'"NumberSuffix":" uur"'),'Spatie voor NumberSuffix ontbreekt op de wire');
    invoiceCheck($decoded['controller']==='invoice'&&$decoded['action']==='add'&&$decoded['Status']===0&&$decoded['VatCalcMethod']==='excl','Envelope of conceptinstellingen gewijzigd');
    foreach($decoded['InvoiceLines'] as $line){
        $keys=array_keys($line);sort($keys);
        $expected=['Number','NumberSuffix','PriceExcl','Description','PeriodicType','StartDate','EndDate'];sort($expected);
        invoiceCheck($keys===$expected,'Onverwacht genest factuurregelveld');
        invoiceCheck(is_float($line['Number'])&&is_float($line['PriceExcl'])&&$line['PeriodicType']==='once','Wire types of periode fout');
    }
}

$payloadB=(new InvoicePayload())->build($built['previews'][2],'2026-10-13');invoiceCheck($payloadB['InvoiceLines'][0]['Description']==='B — Websitewerk','Project ontbreekt in description');
// Allocated rest cents are directly representable as quantity-one amounts.
$h=$hours;$h['entries']=[ $hours['entries'][0],$hours['entries'][0]];$h['entries'][0]['duration']=60;$h['entries'][1]['duration']=60;$h['entries'][1]['id']=125;$h['entries'][1]['description']='Other';
$m=$preview->build($h,[1=>['123','125']],[1=>'description'])['previews'][1];
invoiceCheck($m['duration_seconds']===120 && $m['total_cents']===2125,'Restcentvoorbeeld met exacte minuten klopt niet');
$detailPayload=(new InvoicePayload())->build($m,'2026-10-13');
$detailCents=array_map(static fn($line)=>ApiDecimal::roundedLine($line['Number'],8500),$detailPayload['InvoiceLines']);
invoiceCheck($detailCents===array_column($m['lines'],'amount_cents')&&array_sum($detailCents)===2125,'Restcentverdeling niet exact doorgegeven');
foreach($detailPayload['InvoiceLines'] as $line){invoiceCheck($line['NumberSuffix']===' uur'&&$line['PriceExcl']==='85.00','Omschrijvingregel niet menselijk');}

$summaryModel=$preview->build($h,[1=>['123','125']],[1=>'summary'])['previews'][1];
$summaryPayload=(new InvoicePayload())->build($summaryModel,'2026-10-13');
invoiceCheck($summaryModel['total_cents']===$m['total_cents'] && ApiDecimal::roundedLine($summaryPayload['InvoiceLines'][0]['Number'],8500)===2125,'Samengevat wijkt af van exact lokaal totaal');
invoiceCheck($http->writes===[],'Restcentcontrole veroorzaakte API-write');
$service->prepare('token','owner',$hours,$state,1);invoiceCheck($http->writes===[],'Prepare veroorzaakte write');
$id=$service->confirm('token','owner',$hours,$state);
invoiceCheck($id===1&&count($http->writes)===1&&$repo->action('token','owner')['state']==='succeeded','Succes niet geregistreerd');
$local=$pdo->query('SELECT * FROM invoiced_entries')->fetch(PDO::FETCH_ASSOC);
$registered=$pdo->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
invoiceCheck($registered['hourly_rate_cents']===8500&&$registered['duration_seconds']===900&&json_decode($registered['lines_json'],true)[0]['duration_seconds']===900,'Lokale audit verliest tarief of exacte regelseconden');
invoiceCheck($local['duration_seconds']===900&&$local['invoice_id']===1,'Actual/billable audit ontbreekt');
$annotated=(new LocalInvoiceStatus($pdo))->annotate([$hours['entries'][0]])[0];invoiceCheck(TimeEntryStatus::isInvoiced($annotated)&&!TimeEntryStatus::isBillable($annotated),'Lokale invoice-status niet leidend');
invoiceReject(fn()=>$service->confirm('token','owner',$hours,$state));invoiceCheck(count($http->writes)===1,'Dubbel token maakte tweede invoice');
invoiceReject(fn()=>$service->prepare('other','owner',$hours,$state,1));
foreach(['invoiced','Billed by factuursturen.nl'] as $tag){$e=$hours['entries'][0];$e['tags']=[$tag];invoiceCheck(TimeEntryStatus::isInvoiced($e),'Oude tag kapot');}
// A second project for the SAME debtor is a second invoice, never combined.
$http->response=new HttpResponse(200,'{"controller":"invoice","action":"add","status":"success","invoice":{"Identifier":13,"InvoiceCode":"[concept]13","Status":0,"Debtor":42,"AmountExcl":"21.25"}}');
$service->prepare('project2','owner',$hours,$state,2);$service->confirm('project2','owner',$hours,$state);
invoiceCheck(count($repo->history()['invoices'])===2&&count($http->writes)===2,'Twee projecten samengevoegd');
foreach(['rate','mapping','incomplete','running','tag','entry','fresh'] as $case){
    $s=invoiceSetup();$s['service']->prepare('case','owner',$s['hours'],$s['state'],1);
    if($case==='rate'){$s['projectService']->save('1','42','90');}
    if($case==='mapping'){$s['projectService']->remove('1');}
    if($case==='incomplete'){$s['hours']['complete']=false;}
    if($case==='running'){$s['hours']['entries'][0]['stop']=null;}
    if($case==='tag'){$s['hours']['entries'][0]['tags']=['invoiced'];}
    if($case==='entry'){$s['hours']['entries'][0]['duration']=420;}
    if($case==='fresh'){$changed=$s['hours']['entries'];$changed[0]['duration']=420;$s['service']=new InvoiceService($s['preview'],$s['repo'],new WeFactClient('stub-secret',$s['http']),fn()=>['complete'=>true,'entries'=>$changed],'source');}
    invoiceReject(fn()=>$s['service']->confirm('case','owner',$s['hours'],$s['state']));invoiceCheck($s['http']->writes===[],'Gewijzigde data/configuratie veroorzaakt write');
}
foreach([new HttpResponse(200,'{"status":"error","errors":["stub-secret"]}'),new HttpResponse(200,'{"controller":"invoice","action":"add","status":"success","invoice":{"Identifier":12,"InvoiceCode":"[concept]12","Status":1,"Debtor":42,"AmountExcl":21.25}}'),new HttpResponse(200,'{"controller":"invoice","action":"add","status":"success","invoice":{"Identifier":12,"InvoiceCode":"[concept]12","Status":0,"Debtor":42,"AmountExcl":21.26}}'),new HttpResponse(200,'bad JSON')] as $response){
    $s=invoiceSetup();$s['http']->response=$response;$s['service']->prepare('error','owner',$s['hours'],$s['state'],1);
    invoiceReject(fn()=>$s['service']->confirm('error','owner',$s['hours'],$s['state']));
    invoiceCheck($s['repo']->history()['invoices']===[]&&in_array($s['repo']->action('error','owner')['state'],['reconciliation_required','cancelled'],true),'Onbetrouwbare response als succes geregistreerd');
    invoiceReject(fn()=>$s['service']->confirm('error','owner',$s['hours'],$s['state']));invoiceCheck(count($s['http']->writes)===1,'Retry na onzekere response');
    $e=(new LocalInvoiceStatus($s['pdo']))->annotate([$s['hours']['entries'][0]])[0];invoiceCheck(!TimeEntryStatus::isBillable($e),'Onzeker entry niet geblokkeerd');
}
$s=invoiceSetup();$s['service']->prepare('sql','owner',$s['hours'],$s['state'],1);
$s['pdo']->exec("CREATE TRIGGER fail_invoice BEFORE INSERT ON invoices BEGIN SELECT RAISE(ABORT,'test'); END");
invoiceReject(fn()=>$s['service']->confirm('sql','owner',$s['hours'],$s['state']));
invoiceCheck($s['repo']->action('sql','owner')['state']==='cancelled'&&$s['repo']->history()['invoices']===[],'SQLite failure verliest durable guard');
invoiceCheck($s['repo']->action('sql','owner')['wefact_invoice_id']==='12','Bekend remote-ID niet behouden');
invoiceReject(fn()=>$s['service']->confirm('sql','owner',$s['hours'],$s['state']));invoiceCheck(count($s['http']->writes)===1,'Tweede write na SQLite failure');
// Separate owners/actions sharing an entry cannot both claim it (DB unique reservation guard).
$s=invoiceSetup();$s['service']->prepare('a','ownerA',$s['hours'],$s['state'],1);$s['service']->prepare('b','ownerB',$s['hours'],$s['state'],1);
$m=$s['built']['previews'][1];$p=(new InvoicePayload())->build($m,date('Y-m-d'));
$s['repo']->claim('a','ownerA',$m,$p);invoiceReject(fn()=>$s['repo']->claim('b','ownerB',$m,$p));
invoiceCheck($s['repo']->action('b','ownerB')['state']==='prepared','Concurrerende claim veranderde andere actie');


// Distinct database connections cannot reserve an overlapping entry twice.
$dbPath=sys_get_temp_dir().'/invoice-claims-'.bin2hex(random_bytes(8)).'.sqlite';
$dbA=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$fixture=invoiceSetup($dbA);
$dbB=new PDO('sqlite:'.$dbPath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$repoB=new InvoiceRepository($dbB);
$fixture['service']->prepare('connectionA','A',$fixture['hours'],$fixture['state'],1);
$fixture['service']->prepare('connectionB','B',$fixture['hours'],$fixture['state'],1);
$m=$fixture['built']['previews'][1];$p=(new InvoicePayload())->build($m,date('Y-m-d'));
$fixture['repo']->claim('connectionA','A',$m,$p);
invoiceReject(fn()=>$repoB->claim('connectionB','B',$m,$p));
invoiceCheck((int)$dbB->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===1,'Twee verbindingen maakten twee reserveringen');
$dbA=null;$dbB=null;unlink($dbPath);

// Price/quantity are numeric JSON literals, while the PHP model retains decimal strings.
$wire=\App\WeFact\JsonPayload::encode(['api_key'=>'stub-secret','InvoiceLines'=>[['Number'=>'0.083333333334','PriceExcl'=>'85.00','Description'=>'quote "Number":"99"']]]);
invoiceCheck(str_contains($wire,'"Number":0.083333333334')&&str_contains($wire,'"PriceExcl":85.00'),'Decimalen niet als numerieke JSON verstuurd');

// API error/timeout never permits a retry; all exceptions remain safe.
$s=invoiceSetup();$s['service']->prepare('timeout','owner',$s['hours'],$s['state'],1);
$timeoutHttp=new class implements InvoiceTransport {
    public int $calls=0;
    public function listDebtors(#[\SensitiveParameter]string $key,int $offset,int $limit):HttpResponse{throw new RuntimeException('Unexpected');}
    public function addInvoice(#[\SensitiveParameter]string $key,array $payload):HttpResponse{$this->calls++;throw new RuntimeException('raw secret: stub-secret');}
};
$timeoutService=new InvoiceService($s['preview'],$s['repo'],new WeFactClient('stub-secret',$timeoutHttp),fn($h)=>['complete'=>true,'entries'=>$h['entries']],'source');
invoiceReject(fn()=>$timeoutService->confirm('timeout','owner',$s['hours'],$s['state']));
invoiceReject(fn()=>$timeoutService->confirm('timeout','owner',$s['hours'],$s['state']));
invoiceCheck($timeoutHttp->calls===1&&$s['repo']->action('timeout','owner')['state']==='reconciliation_required','Timeout leidde tot nieuwe write');

// Cancellation can be followed by an explicitly NEW preview revision.
$s=invoiceSetup();$s['service']->prepare('cancel','owner',$s['hours'],$s['state'],1);$s['repo']->cancel('cancel','owner');
invoiceReject(fn()=>$s['service']->confirm('cancel','owner',$s['hours'],$s['state']));
$s['state']['revision']='new-revision';$s['service']->prepare('new','owner',$s['hours'],$s['state'],1);
invoiceCheck($s['repo']->action('new','owner')['state']==='prepared'&&$s['http']->writes===[],'Nieuw bevestigen na annulering onmogelijk');

// Existing registrations from the old schema remain protected after migration.
$legacy=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['001_foundation.sql','002_wefact_debtors.sql','003_project_mappings.sql']as $version){$legacy->exec(file_get_contents(dirname(__DIR__).'/database/migrations/'.$version));}
$legacy->exec("CREATE TABLE migrations(version TEXT PRIMARY KEY,applied_at TEXT NOT NULL)");
foreach(['001_foundation.sql','002_wefact_debtors.sql','003_project_mappings.sql']as $version){$q=$legacy->prepare('INSERT INTO migrations VALUES(?,?)');$q->execute([$version,gmdate('c')]);}
$legacy->exec("INSERT INTO invoiced_entries(toggl_time_entry_id,toggl_project_id,wefact_invoice_id,duration,hourly_rate,invoiced_at,created_at) VALUES(999,1,'12',60,85,'test','test')");
(new Migrator($legacy,dirname(__DIR__).'/database/migrations'))->migrate();
$e=['id'=>999,'duration'=>60,'stop'=>'2026-10-10T12:00:00Z','tags'=>[]];
$e=(new LocalInvoiceStatus($legacy))->annotate([$e])[0];invoiceCheck(TimeEntryStatus::isInvoiced($e),'Legacy registratie verloren');


// Explicit context-matching API validation failure is a normal failed action, not an unknown write.
$s=invoiceSetup();
$s['http']->response=new HttpResponse(200,'{"controller":"invoice","action":"add","status":"error","code":"INVALID_FIELD","errors":["Number invalid; api_key=stub-secret","password=another-private-value"]}',
    ['curl_errno'=>0,'request_size_bytes'=>500,'elapsed_ms'=>12]);
$s['service']->prepare('rejected','owner',$s['hours'],$s['state'],1);
invoiceReject(fn()=>$s['service']->confirm('rejected','owner',$s['hours'],$s['state']));
$failed=$s['repo']->history()['failed'];
invoiceCheck(count($failed)===1&&$failed[0]['outcome']==='rejected'&&$failed[0]['state']==='cancelled','Expliciete fout onterecht onzeker');
invoiceCheck((int)$s['pdo']->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===0&&$s['repo']->history()['invoices']===[],'Zeker afgewezen actie houdt toekomstige entries vast');
$diag=json_decode($failed[0]['diagnostics_json'],true);
invoiceCheck($diag['http_status']===200&&$diag['api_status']==='error'&&$diag['api_code']==='INVALID_FIELD'&&str_contains($diag['api_errors'][0],'Number invalid'),'API-diagnostiek verloren');
invoiceCheck(!str_contains($failed[0]['diagnostics_json'],'stub-secret')&&!str_contains($failed[0]['diagnostics_json'],'another-private-value'),'Secrets in diagnostiek');
invoiceReject(fn()=>$s['service']->confirm('rejected','owner',$s['hours'],$s['state']));
invoiceCheck(count($s['http']->writes)===1,'Afgewezen token opnieuw verstuurd');
$s['state']['revision']='new-after-reject';$s['service']->prepare('fresh-after-reject','owner',$s['hours'],$s['state'],1);
invoiceCheck($s['repo']->action('fresh-after-reject','owner')['state']==='prepared','Nieuwe gecontroleerde poging na zekere afwijzing onmogelijk');

// An error with partial invoice evidence, or a server error, remains uncertain.
foreach([
    new HttpResponse(200,'{"controller":"invoice","action":"add","status":"error","errors":["Failure"],"invoice":{"Identifier":12}}'),
    new HttpResponse(500,'{"controller":"invoice","action":"add","status":"error","errors":["Internal failure"]}'),
    new HttpResponse(200,'{"controller":"unexpected","action":"add","status":"error","errors":["Failure"]}'),
]as$response){
    $s=invoiceSetup();$s['http']->response=$response;$s['service']->prepare('ambiguous','owner',$s['hours'],$s['state'],1);
    invoiceReject(fn()=>$s['service']->confirm('ambiguous','owner',$s['hours'],$s['state']));
    invoiceCheck($s['repo']->action('ambiguous','owner')['state']==='reconciliation_required'&&(int)$s['pdo']->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===1,'Onzekere uitkomst onveilig vrijgegeven');
}
$s=invoiceSetup();$s['http']->response=new HttpResponse(200,'not-json');
$s['service']->prepare('parse','owner',$s['hours'],$s['state'],1);invoiceReject(fn()=>$s['service']->confirm('parse','owner',$s['hours'],$s['state']));
$record=$s['repo']->history()['uncertain'][0];$diag=json_decode($record['diagnostics_json'],true);
invoiceCheck($diag['phase']==='parse'&&$diag['response_received']&&$diag['http_status']===200&&$diag['response_format']==='non_json','Ontvangen maar onleesbare response niet onderscheiden');
invoiceCheck(!str_contains($record['diagnostics_json'],'not-json'),'Ruwe responsebody gelogd');

// Existing unknown action/reservations must not be touched by diagnosing a DIFFERENT rejected action.
$s=invoiceSetup();$s['service']->prepare('existing-unknown','owner',$s['hours'],$s['state'],1);
$m=$s['built']['previews'][1];$s['repo']->claim('existing-unknown','owner',$m,(new InvoicePayload())->build($m,date('Y-m-d')));
$before=$s['repo']->action('existing-unknown','owner');
$s['http']->response=new HttpResponse(422,'{"controller":"invoice","action":"add","status":"error","errors":["Description invalid"]}');
$s['service']->prepare('other-rejected','owner',$s['hours'],$s['state'],2);
invoiceReject(fn()=>$s['service']->confirm('other-rejected','owner',$s['hours'],$s['state']));
invoiceCheck($before===$s['repo']->action('existing-unknown','owner')&&(int)$s['pdo']->query("SELECT count(*) FROM invoice_entry_reservations WHERE token_hash=".$s['pdo']->quote(hash('sha256','existing-unknown')))->fetchColumn()===1,'Bestaande onzekere blokkade aangetast');
$history=$s['repo']->history();
ob_start();(new View(dirname(__DIR__).'/templates'))->page('Test','Historie','','/facturen',['history'=>$history,'message'=>null,'error'=>null]);$html=ob_get_clean();
invoiceCheck(!str_contains($html,'Mislukte factuuractie')&&!str_contains($html,'Description invalid')&&str_contains($html,'Handmatige controle vereist')&&str_contains($html,'geen HTTP-status'),'Afgesloten actie zichtbaar of open diagnostiek verdwenen');
invoiceCheck(count($history['failed'])===1&&str_contains($history['failed'][0]['diagnostics_json'],'Description invalid'),'Afgesloten diagnostiek intern verloren');

// Fractional-cent quarter splits must fail before prepare/claim/external write.
$fraction=invoiceSetup();$fraction['projectService']->save('1','42','85.02');
$fraction['hours']['entries'][1]['project_id']=1;$fraction['hours']['entries'][1]['description']='Andere werkzaamheden';
$fBuilt=$fraction['preview']->build($fraction['hours'],[1=>['123','124']],[1=>'description']);
$fState=['dataset_id'=>'one','selection'=>$fBuilt['selection'],'modes'=>$fBuilt['modes'],'model_hashes'=>array_map([InvoiceRepository::class,'modelHash'],$fBuilt['previews'])];
invoiceReject(fn()=>$fraction['service']->prepare('fraction','owner',$fraction['hours'],$fState,1));
invoiceCheck($fraction['http']->writes===[]&&(int)$fraction['pdo']->query('SELECT count(*) FROM invoice_actions')->fetchColumn()===0,'Restcentpayload bereikt write/claim');

// A successful registration preserves exact seconds separately from project-billed seconds.
$rounded=invoiceSetup();$rounded['hours']['entries'][0]['duration']=360;
$rBuilt=$rounded['preview']->build($rounded['hours'],[1=>['123']],[]);
$rounded['state']=['dataset_id'=>'one','selection'=>$rBuilt['selection'],'modes'=>$rBuilt['modes'],'model_hashes'=>array_map([InvoiceRepository::class,'modelHash'],$rBuilt['previews'])];
$rounded['service']->prepare('rounded','owner',$rounded['hours'],$rounded['state'],1);
$rounded['service']->confirm('rounded','owner',$rounded['hours'],$rounded['state']);
$rInvoice=$rounded['pdo']->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
invoiceCheck($rInvoice['duration_seconds']===360&&$rInvoice['billed_duration_seconds']===900&&$rInvoice['amount_excl_cents']===2125,'Factuursnapshot verliest exacte/afgeronde projecttijd');
$rLines=json_decode($rInvoice['lines_json'],true);
invoiceCheck($rLines[0]['duration_seconds']===360&&$rLines[0]['billed_duration_seconds']===900&&$rounded['pdo']->query('SELECT duration_seconds FROM invoiced_entries')->fetchColumn()===360,'Entrysnapshot individueel afgerond');
$beforeMigration=$rInvoice;
// Simulate an upgrade from schema 007 on an existing exact-time invoice.
$rounded['pdo']->exec("DELETE FROM migrations WHERE version='008_project_quarter_hour.sql'");
$rounded['pdo']->exec('ALTER TABLE invoices DROP COLUMN billed_duration_seconds');
(new Migrator($rounded['pdo'],dirname(__DIR__).'/database/migrations'))->migrate();
$historical=$rounded['pdo']->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
invoiceCheck($historical['billed_duration_seconds']===360&&$historical['amount_excl_cents']===$beforeMigration['amount_excl_cents'],'Migration herberekent historische facturen');

// Manual deletion is a local lifecycle, preserving immutable success snapshots.
$deleted=invoiceSetup();$deleted['service']->prepare('delete-test','owner',$deleted['hours'],$deleted['state'],1);
$deleted['service']->confirm('delete-test','owner',$deleted['hours'],$deleted['state']);
$actionBefore=$deleted['repo']->action('delete-test','owner');
$invoiceBefore=$deleted['pdo']->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
$entryBefore=$deleted['pdo']->query('SELECT * FROM invoiced_entries')->fetch(PDO::FETCH_ASSOC);
invoiceCheck(TimeEntryStatus::isInvoiced((new LocalInvoiceStatus($deleted['pdo']))->annotate([$deleted['hours']['entries'][0]])[0]),'Normale factuur blokkeert niet');
invoiceReject(fn()=>$deleted['repo']->closeDeletedTest(1,'Wrong','12','[concept]12',[123],'Test'));
invoiceReject(fn()=>$deleted['repo']->closeDeletedTest(1,'A','12','[concept]12',[999],'Test'));
$deleted['repo']->prepare('foreign-reservation','owner','other','source',$deleted['built']['previews'][1]);
$q=$deleted['pdo']->prepare('UPDATE invoice_entry_reservations SET token_hash=? WHERE toggl_time_entry_id=?');$q->execute([hash('sha256','foreign-reservation'),123]);
invoiceReject(fn()=>$deleted['repo']->closeDeletedTest(1,'A','12','[concept]12',[123],'Test'));
invoiceCheck($deleted['pdo']->query('SELECT lifecycle FROM invoices')->fetchColumn()==='active','Weigering wijzigde lifecycle');
$q->execute([hash('sha256','delete-test'),123]);
$deleted['repo']->closeDeletedTest(1,'A','12','[concept]12',[123],'Handmatig verwijderd testconcept');
invoiceCheck((int)$deleted['pdo']->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===0,'Eigen reservering bleef achter');
$invoiceAfter=$deleted['pdo']->query('SELECT * FROM invoices')->fetch(PDO::FETCH_ASSOC);
foreach($invoiceBefore as$key=>$value){if(in_array($key,['lifecycle','closed_at','closure_reason'],true)){continue;}invoiceCheck($invoiceAfter[$key]===$value,'Factuursnapshot gewijzigd');}
$entryAfter=$deleted['pdo']->query('SELECT * FROM invoiced_entries')->fetch(PDO::FETCH_ASSOC);unset($entryBefore['released_at'],$entryAfter['released_at']);invoiceCheck($entryBefore===$entryAfter,'Entrysnapshot verloren');
invoiceCheck($deleted['repo']->action('delete-test','owner')===$actionBefore,'Originele successactie gewijzigd');
$e=(new LocalInvoiceStatus($deleted['pdo']))->annotate([$deleted['hours']['entries'][0]])[0];invoiceCheck(!TimeEntryStatus::isInvoiced($e)&&TimeEntryStatus::isBillable($e),'Verwijderd testconcept blokkeert nog');
$h=$deleted['repo']->history();invoiceCheck($h['invoices']===[]&&count($h['closed_invoices'])===1,'Afgesloten concept niet behouden');
ob_start();(new View(dirname(__DIR__).'/templates'))->page('Test','Historie','','/facturen',['history'=>$h,'message'=>null,'error'=>null]);$closedHtml=ob_get_clean();
invoiceCheck(!str_contains($closedHtml,'Afgesloten factuuracties')&&!str_contains($closedHtml,'[concept]12 / 12'),'Afgesloten testconcept nog zichtbaar');
invoiceCheck(!isset($deleted['repo']->history(includeClosed:false)['closed_invoices'])&&count($deleted['repo']->history()['closed_invoices'])===1,'Presentatiefilter verloor interne historie');
invoiceReject(fn()=>$deleted['repo']->closeDeletedTest(1,'A','12','[concept]12',[123],'Opnieuw'));
// Rebilling is possible in an isolated stub database, retaining both historical rows.
$deleted['http']->response=new HttpResponse(200,'{"controller":"invoice","action":"add","status":"success","invoice":{"Identifier":99,"InvoiceCode":"[concept]99","Status":0,"Debtor":42,"AmountExcl":"21.25"}}');
$deleted['state']['revision']='new-explicit-preview';
$deleted['service']->prepare('new-after-delete','owner',$deleted['hours'],$deleted['state'],1);
$deleted['service']->confirm('new-after-delete','owner',$deleted['hours'],$deleted['state']);
invoiceCheck((int)$deleted['pdo']->query('SELECT count(*) FROM invoiced_entries')->fetchColumn()===2,'Rebill verloor historische entry');
$e=(new LocalInvoiceStatus($deleted['pdo']))->annotate([$deleted['hours']['entries'][0]])[0];invoiceCheck(TimeEntryStatus::isInvoiced($e)&&!TimeEntryStatus::isBillable($e),'Nieuwe actieve registratie blokkeert niet');
invoiceReject(fn()=>$deleted['service']->prepare('duplicate-after-rebill','owner',$deleted['hours'],$deleted['state'],1));

$temp=sys_get_temp_dir().'/invoice-config-'.bin2hex(random_bytes(8));mkdir($temp);file_put_contents($temp.'/.env',"TOGGL_API_TOKEN=stub-token\nWEFACT_API_KEY=stub-secret\n");
$s=invoiceSetup();$config=new Config($temp);$view=new View(dirname(__DIR__).'/templates');
try{
    TogglSession::open($config);$owner=hash('sha256',session_id());$sessionId=session_id();$_SESSION['toggl_hours']=$s['hours'];$_SESSION['invoice_preview']=$s['state'];$csrf=$_SESSION['csrf'];session_write_close();
    $tagCalls=0;
    $confirmation=new ConfirmationController($config,$view,$s['service'],$s['repo'],function(int $id,bool $retry)use(&$tagCalls,$s):array{
        $tagCalls++;
        invoiceCheck($retry===false&&(int)$s['pdo']->query('SELECT count(*) FROM invoices')->fetchColumn()===1&&$s['pdo']->query('SELECT state FROM invoice_actions')->fetchColumn()==='succeeded','Tagging gestart vóór succesvolle lokale registratie');
        throw new RuntimeException('stub-secret private tagging failure');
    });
    $previewController=new PreviewController($config,$view,$s['preview']);
    // Reproduce: a visible default preview has no saved state, yet its prepare button must not silently ignore clicks.
    TogglSession::open($config);unset($_SESSION['invoice_preview']);session_write_close();
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    ob_start();$previewController->handle();$defaultHtml=ob_get_clean();
    $doc=new DOMDocument();@$doc->loadHTML($defaultHtml);$xpath=new DOMXPath($doc);
    $button=$xpath->query('//button[@form="prepare-1"]')->item(0);
    invoiceCheck($button!==null&&!$button->hasAttribute('disabled'),'Prepare-knop stil disabled bij zichtbare preview');
    invoiceCheck(str_contains($defaultHtml,'Klik eerst op Factuur-preview bijwerken'),'Ontbrekende previewstate zonder uitleg');
    $form=$xpath->query('//form[@id="prepare-1"]')->item(0);
    invoiceCheck($form!==null&&$form->getAttribute('method')==='post'&&$form->getAttribute('action')==='/concept-bevestigen','Verkeerde form-associatie/action/method');
    $post=[];
    foreach($xpath->query('.//input[@name]',$form)as$input){$post[$input->getAttribute('name')]=$input->getAttribute('value');}
    invoiceCheck($post['csrf']===$csrf&&$post['action']==='prepare'&&$post['project_id']==='1','HTML stuurt verkeerde prepare-data');
    $_SERVER['REQUEST_METHOD']='POST';$_POST=$post;
    ob_start();$confirmation->handle();ob_end_clean();
    invoiceCheck(http_response_code()===303&&$s['http']->writes===[],'Mislukte prepare redirect/write fout');
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    ob_start();$confirmation->handle();$missingHtml=ob_get_clean();
    invoiceCheck(str_contains($missingHtml,'role="alert"')&&str_contains($missingHtml,'Werk eerst een geldige project-preview bij'),'Prepare zonder sessiestate faalt onzichtbaar');
    invoiceCheck(str_contains($missingHtml,'Terug naar de preview om deze bij te werken'),'Fout geeft geen vervolgstap');

    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'dataset_id'=>'one','selected'=>[1=>['123']],'modes'=>[1=>'summary']];
    ob_start();$previewController->handle();ob_end_clean();invoiceCheck($s['http']->writes===[],'Preview POST maakte write');
    $_POST=['csrf'=>'invalid','action'=>'prepare','project_id'=>'1'];ob_start();$confirmation->handle();ob_end_clean();invoiceCheck(http_response_code()===403&&$s['http']->writes===[],'CSRF faalt');
    $_POST=['csrf'=>$csrf,'action'=>'prepare','project_id'=>'1'];ob_start();$confirmation->handle();ob_end_clean();invoiceCheck($s['http']->writes===[],'Prepare controller maakte write');
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];ob_start();$confirmation->handle();$html=ob_get_clean();invoiceCheck(str_contains($html,'Conceptfactuur aanmaken in WeFact')&&$s['http']->writes===[],'Confirmation GET fout');
    invoiceCheck(str_contains($html,'Geselecteerde entry-IDs: 123')&&str_contains($html,'<dt>Factureerbare tijd</dt><dd>0:15</dd>'),'Bevestiging gebruikt verkeerde/exacte duration');
    $validDisplay=$s['preview']->presentation($s['hours'],[],new DateTimeZone('Europe/Amsterdam'),$_SESSION['invoice_preview']);
    invoiceCheck($validDisplay['overview']['groups'][0]['confirmable']&&$validDisplay['overview']['groups'][0]['prepare_issue']===null,'Geldige preview niet prepare-gereed');

    preg_match('/name="token" value="([^"]+)"/',$html,$match);
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'action'=>'confirm','token'=>$match[1],'Status'=>2,'Debtor'=>99];ob_start();$confirmation->handle();ob_end_clean();
    invoiceCheck(count($s['http']->writes)===1&&$s['http']->writes[0]['Status']===0&&$s['http']->writes[0]['Debtor']===42,'Expliciete confirmation of vertrouwde payload fout');
    invoiceCheck($tagCalls===1&&$s['pdo']->query('SELECT state FROM invoice_actions')->fetchColumn()==='succeeded','Tagfailure wijzigde succesvol factuurresultaat');
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];ob_start();(new HistoryController($config,$view,$s['repo']))->handle();$html=ob_get_clean();invoiceCheck(str_contains($html,'[concept]12')&&count($s['http']->writes)===1&&$s['http']->reads===0,'Historie gebruikt API');
    invoiceCheck(str_contains($html,'De factuur blijft succesvol')&&!str_contains($html,'stub-secret'),'Tagfailure niet apart/veilig getoond');
    // Manual closure remains internal and does not reappear in the public history.
    $originalTimezone=date_default_timezone_get();date_default_timezone_set('Europe/Amsterdam');
    $manualHtml=(static function():string {
        $message='';$error='';
        $history=['uncertain'=>[],'invoices'=>[],'failed'=>[[
            'snapshot_json'=>json_encode(['project_name'=>'Example Project']),
            'created_at'=>'2026-10-13T13:01:32+00:00',
            'message'=>'Handmatig vastgesteld: geen conceptfactuur aangemaakt <controle>.',
            'diagnostics_json'=>json_encode(['manual_review'=>['result'=>'no_invoice_created','checked_at'=>'2026-10-13T14:00:00+00:00']])
        ]]];
        ob_start();require dirname(__DIR__).'/templates/invoices.php';return ob_get_clean();
    })();
    date_default_timezone_set($originalTimezone);
    invoiceCheck(!str_contains($manualHtml,'Handmatig gecontroleerd en afgesloten')&&!str_contains($manualHtml,'Handmatig vastgesteld:')&&!str_contains($manualHtml,'Afgesloten factuuracties'),'Handmatige afsluiting hoort niet zichtbaar te zijn');
    // Cancel/back requires a fresh explicit preview, never recycling a closed confirmation.
    $cancelFixture=invoiceSetup();$cancelController=new ConfirmationController($config,$view,$cancelFixture['service'],$cancelFixture['repo']);
    TogglSession::open($config);$_SESSION['toggl_hours']=$cancelFixture['hours'];$_SESSION['invoice_preview']=$cancelFixture['state'];unset($_SESSION['invoice_tokens'],$_SESSION['invoice_confirmation']);session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'action'=>'prepare','project_id'=>'1'];ob_start();$cancelController->handle();ob_end_clean();
    $cancelToken=$_SESSION['invoice_confirmation'];
    $_POST=['csrf'=>$csrf,'action'=>'cancel','token'=>$cancelToken];ob_start();$cancelController->handle();ob_end_clean();
    invoiceCheck(!isset($_SESSION['invoice_confirmation'],$_SESSION['invoice_preview'])&&$cancelFixture['pdo']->query('SELECT state FROM invoice_actions')->fetchColumn()==='cancelled','Annuleren laat bruikbare preview/token achter');
    $_POST=['csrf'=>$csrf,'action'=>'prepare','project_id'=>'1'];ob_start();$cancelController->handle();ob_end_clean();
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];ob_start();$cancelController->handle();$cancelHtml=ob_get_clean();
    invoiceCheck(str_contains($cancelHtml,'Werk eerst een geldige project-preview bij')&&!str_contains($cancelHtml,'data-final-confirm'),'Na annuleren verschijnt oude bevestiging');
    TogglSession::open($config);$_SESSION['invoice_preview']=$cancelFixture['state']+['revision'=>'after-cancel'];session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'action'=>'prepare','project_id'=>'1'];ob_start();$cancelController->handle();ob_end_clean();
    invoiceCheck($_SESSION['invoice_confirmation']!==$cancelToken&&(int)$cancelFixture['pdo']->query('SELECT count(*) FROM invoice_actions')->fetchColumn()===2&&$cancelFixture['http']->writes===[],'Nieuwe expliciete preview hergebruikt afgesloten token of schrijft extern');
    // Unexpected storage exception must reach the UI without leaking raw exception text.
    $freshFixture=invoiceSetup();
    $failingRepo=new class($freshFixture['pdo'])extends InvoiceRepository {
        public function prepare(string $token,string $owner,string $dataset,string $source,array $model,array $payload=[],string $revision=''):void {throw new RuntimeException('private storage details stub-secret');}
    };
    $failingService=new InvoiceService($freshFixture['preview'],$failingRepo,new WeFactClient('stub-secret',$freshFixture['http']),fn($h)=>['complete'=>true,'entries'=>$h['entries']],'source');
    $failingController=new ConfirmationController($config,$view,$failingService,$failingRepo);
    TogglSession::open($config);$_SESSION['toggl_hours']=$freshFixture['hours'];$_SESSION['invoice_preview']=$freshFixture['state'];unset($_SESSION['invoice_tokens'],$_SESSION['invoice_confirmation']);session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'action'=>'prepare','project_id'=>'1'];
    ob_start();$failingController->handle();ob_end_clean();
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];
    ob_start();$failingController->handle();$failureHtml=ob_get_clean();
    invoiceCheck(str_contains($failureHtml,'Factuuractie kon niet veilig worden uitgevoerd')&&str_contains($failureHtml,'role="alert"')&&!str_contains($failureHtml,'private storage')&&!str_contains($failureHtml,'stub-secret'),'Exception verdwijnt of lekt details');
    invoiceCheck($freshFixture['http']->writes===[]&&$freshFixture['http']->reads===0,'Prepare-fout doet externe API-call');
    $incomplete=$freshFixture['hours'];$incomplete['complete']=false;
    $display=$freshFixture['preview']->presentation($incomplete,[],new DateTimeZone('Europe/Amsterdam'),$freshFixture['state']);
    invoiceCheck(str_contains($display['overview']['groups'][0]['prepare_issue'],'onvolledig'),'Onvolledige dataset zonder prepare-feedback');
}finally{if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}if(isset($sessionId)){$file=dirname(__DIR__).'/storage/sess_'.$sessionId;if(is_file($file)){unlink($file);}}unlink($temp.'/.env');rmdir($temp);}
echo "Invoice-tests geslaagd: expliciete confirmation, payload/decimalen, response/centen, lokale registratie, dubbele/overlappende claims, gewijzigde preview, onzekerheid/SQLite failure, CSRF en historie zonder API.\n";
