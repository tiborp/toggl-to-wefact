<?php
declare(strict_types=1);
// Names, remote IDs and invoice references below are synthetic fixtures.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Invoice\{InvoiceRepository,TagSyncService,LocalInvoiceStatus,HistoryController};
use App\Toggl\{TagTransport,HttpResponse,TogglClient,Quota,TimeEntryStatus,TogglSession};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
function tagCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function tagReject(callable $call):void{try{$call();}catch(Throwable){return;}throw new RuntimeException('Weigering verwacht');}
final class TagStub implements TagTransport{
 public ?HttpResponse $response=null;public array $calls=[];public array $entries=[];public array $fail=[];public array $statuses=[];public bool $throw=false;public bool $malformed=false;
 public function get(string $path,#[\SensitiveParameter] string $token):HttpResponse{throw new RuntimeException('Onverwachte GET');}
 public function addInvoiced(int $workspace,int $entryId,#[\SensitiveParameter] string $token):HttpResponse{
  $this->calls[]=[$workspace,$entryId,App\Toggl\CurlTransport::INVOICED_UPDATE];
  if($this->response!==null){return $this->response;}
  if($this->throw){throw new RuntimeException('private stub-token credentials');}
  $status=$this->statuses[$entryId]??(in_array($entryId,$this->fail,true)?400:200);
  if($status===200){$this->entries[$entryId]['tags']=array_values(array_unique([...$this->entries[$entryId]['tags'],'invoiced']));}
  return new HttpResponse($status,$this->malformed?'private stub-token':json_encode($status===200?$this->entries[$entryId]:['error'=>'stub-token private failure']),['x-toggl-quota-remaining'=>$status===429?'0':'29','x-toggl-quota-resets-in'=>'60']);
 }
}
function tagFixture(int $count=25):array{
 $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->exec('PRAGMA foreign_keys=ON');(new Migrator($p,dirname(__DIR__).'/database/migrations'))->migrate();
 $entries=[];for($id=1;$id<=$count;$id++){$entries[]=['id'=>$id,'project_id'=>1,'workspace_id'=>100,'duration'=>900,'description'=>'Werk','start'=>'2026-10-10T10:00:00Z','stop'=>'2026-10-10T10:15:00Z','tags'=>['development','client']];}
 $model=['toggl_project_id'=>1,'project_name'=>'Example Project','wefact_debtor_id'=>'42','wefact_display_name'=>'Example Client','entry_ids'=>array_column($entries,'id'),'entries'=>$entries,'duration_seconds'=>$count*900,'billed_duration_seconds'=>$count*900,'hourly_rate_cents'=>10000,'total_cents'=>$count*2500,'mode'=>'summary','lines'=>[],'from'=>'2026-10-10','to'=>'2026-10-10'];
 $repo=new InvoiceRepository($p);$repo->prepare('token','owner','dataset','scope',$model);$repo->claim('token','owner',$model,[]);
 $repo->complete('token','owner',$model,['id'=>'91001','code'=>'EXAMPLE-001'],'scope','2026-10-10');
 $http=new TagStub();foreach($entries as$e){$http->entries[$e['id']]=$e;}
 $quota=new Quota();$refreshes=0;
 $refresh=function($period)use($http,&$refreshes){$refreshes++;return ['complete'=>true,'entries'=>array_values($http->entries)];};
 $sync=new TagSyncService($p,new TogglClient('stub-token',$http,$quota),'scope',$refresh);
 return compact('p','entries','repo','http','quota','sync','model');
}
$f=tagFixture();$before=$f['repo']->action('token','owner');$r=$f['sync']->sync(1);
tagCheck($r===['total'=>25,'synced'=>25,'failed'=>0,'unknown'=>0,'pending'=>0]&&count($f['http']->calls)===25,'25 pending entries vereisen maximaal 25 PUTs');
tagCheck(App\Toggl\CurlTransport::invoicedEndpoint(9001001,9002000001)==='/workspaces/9001001/time_entries/9002000001','PUT endpoint fout');
tagCheck(App\Toggl\CurlTransport::INVOICED_UPDATE===['tags'=>['invoiced'],'tag_action'=>'add'],'Exacte toevoegende payload fout');
foreach($f['http']->entries as$e){tagCheck($e['tags']===['development','client','invoiced'],'Bestaande tags overschreven');}
foreach($f['http']->calls as$call){tagCheck($call[2]===['tags'=>['invoiced'],'tag_action'=>'add'],'Bestaande tags opnieuw meegestuurd');}
tagCheck($before===$f['repo']->action('token','owner'),'Factuuractie gewijzigd');
$f['sync']->sync(1,true);tagCheck(count($f['http']->calls)===25,'Synced entries opnieuw geschreven');
$f=tagFixture(3);$q=$f['p']->prepare('UPDATE invoiced_entries SET tags_json=? WHERE toggl_time_entry_id=?');$q->execute([json_encode(['INVOICED']),1]);$q->execute([json_encode(['bIlLeD By FaCtUuRsTuReN.nL']),2]);
$r=$f['sync']->sync(1);tagCheck($r['synced']===3&&array_column($f['http']->calls,1)===[3],'Historische tags niet overgeslagen');
$f=tagFixture();$f['http']->fail=[23,24,25];$r=$f['sync']->sync(1);
tagCheck($r['synced']===22&&$r['failed']===3&&$r['pending']===0,'Gedeeltelijk succes niet behouden');
foreach((new LocalInvoiceStatus($f['p']))->annotate($f['entries'])as$e){tagCheck(TimeEntryStatus::isInvoiced($e)&&!TimeEntryStatus::isBillable($e),'Tagfailure geeft entries vrij');}
tagCheck($f['repo']->action('token','owner')['state']==='succeeded','Tagfailure maakt factuur onzeker');
$f['http']->fail=[];$f['sync']->sync(1,true);tagCheck(array_column(array_slice($f['http']->calls,25),1)===[23,24,25],'Retry schrijft succesvolle entries opnieuw');
$f=tagFixture();$f['http']->fail=range(1,25);$r=$f['sync']->sync(1);tagCheck($r['failed']===25&&count($f['http']->calls)===25,'Volledige failure of automatische retry fout');
$f=tagFixture();$f['http']->statuses[4]=429;$r=$f['sync']->sync(1);tagCheck($r===['total'=>25,'synced'=>3,'failed'=>1,'unknown'=>0,'pending'=>21]&&count($f['http']->calls)===4,'Rate limit stopt resterende writes niet');
$f['sync']->sync(1,true);tagCheck(count($f['http']->calls)===4,'Rate-limitblokkade genegeerd');
$f=tagFixture(2);$f['http']->malformed=true;$r=$f['sync']->sync(1);tagCheck($r['unknown']===2&&$r['synced']===0,'Onzekere response niet apart geregistreerd');
$f['http']->malformed=false;$f['sync']->sync(1,true);tagCheck(count($f['http']->calls)===2,'Onzeker maar remote getagd opnieuw geschreven');
$f=tagFixture(3);$f['http']->throw=true;$r=$f['sync']->sync(1);tagCheck($r['unknown']===1&&$r['pending']===2&&count($f['http']->calls)===1,'Transportfout leidt tot automatische retry');
$f=tagFixture(30);$r=$f['sync']->sync(1);tagCheck($r['synced']===25&&$r['pending']===5&&count($f['http']->calls)===25,'Veilig PUT-budget fout');
// A later local failure does not roll back earlier confirmed entries or diagnostics.
$f=tagFixture(3);$f['p']->exec("CREATE TRIGGER fail_third BEFORE UPDATE ON invoiced_entries WHEN NEW.toggl_time_entry_id=3 BEGIN SELECT RAISE(ABORT,'local failure'); END");tagReject(fn()=>$f['sync']->sync(1));
tagCheck((int)$f['p']->query('SELECT sum(tag_synced) FROM invoiced_entries')->fetchColumn()===2&&(int)$f['p']->query('SELECT count(*) FROM toggl_tag_sync_attempts')->fetchColumn()===2,'Eerdere successen teruggedraaid');
$f=tagFixture(1);$f['repo']->closeDeletedTest(1,'Example Project','91001','EXAMPLE-001',[1],'Verwijderd testconcept');tagReject(fn()=>$f['sync']->sync(1));tagCheck($f['http']->calls===[],'Afgesloten factuur getagd');
$f=tagFixture(1);$wrong=new TagSyncService($f['p'],new TogglClient('stub-token',$f['http'],$f['quota']),'other');tagReject(fn()=>$wrong->sync(1));tagCheck($f['http']->calls===[],'Andere accountscope getagd');
$f=tagFixture(1);$incomplete=new TagSyncService($f['p'],new TogglClient('stub-token',$f['http'],$f['quota']),'scope',fn($p)=>['complete'=>false,'entries'=>[]]);tagReject(fn()=>$incomplete->sync(1,true));tagCheck($f['http']->calls===[],'Onvolledige preflight schrijft');
// Safe diagnostics: old PATCH history remains next to new PUT attempts.
$f=tagFixture(1);$f['p']->exec("INSERT INTO toggl_tag_sync_attempts(invoice_id,diagnostics_json,created_at) VALUES(1,'{\"method\":\"PATCH\",\"http_status\":500,\"response_body\":\"Invalid path\",\"entry_count\":25,\"endpoint\":\"old\",\"attempted_at\":\"2026-10-10T08:20:00+00:00\"}','old')");
$auth=base64_encode('stub-token:api_token');$f['http']->response=new HttpResponse(400,json_encode(['error'=>'Invalid <script>','api_token'=>'stub-token','password'=>'private-pass','detail'=>'Bearer private-bearer Basic '.$auth]),['x-toggl-quota-remaining'=>'29','Authorization'=>'Basic '.$auth,'Set-Cookie'=>'private-cookie']);
$f['sync']->sync(1);$attempts=$f['p']->query('SELECT * FROM toggl_tag_sync_attempts ORDER BY id')->fetchAll();$diag=json_decode($attempts[1]['diagnostics_json'],true);
tagCheck(count($attempts)===2&&$diag['http_status']===400&&$diag['method']==='PUT'&&$diag['entry_id']===1&&$diag['endpoint']==='/api/v9/workspaces/100/time_entries/1'&&$diag['payload']===['tags'=>['invoiced'],'tag_action'=>'add'],'PUT diagnostiek/audit verloren');
foreach(['stub-token',$auth,'private-pass','private-bearer','private-cookie']as$secret){tagCheck(!str_contains($attempts[1]['diagnostics_json'],$secret),'Credential opgeslagen');}
$history=$f['repo']->history();$message=null;$error=null;$csrf='test';ob_start();require dirname(__DIR__).'/templates/invoices.php';$html=ob_get_clean();tagCheck(str_contains($html,'Toggl: 0/1 gesynchroniseerd')&&str_contains($html,'1 nog niet gesynchroniseerd')&&str_contains($html,'Toggl-tags opnieuw synchroniseren'),'Functionele syncstatus ontbreekt');
foreach(['Toggl-syncdiagnostiek','HTTP 400','HTTP 500','Invalid path','PATCH','PUT','/api/v9','tag_action','response_body','<details>']as$technical){tagCheck(!str_contains($html,$technical),'Technische Toggl-diagnostiek zichtbaar');}
tagCheck($f['p']->query('SELECT * FROM toggl_tag_sync_attempts ORDER BY id')->fetchAll()===$attempts,'Presentatie verwijdert auditgegevens');
$body=str_repeat('x',9000).' stub-token';$safe=App\Toggl\TagDiagnostic::response(new HttpResponse(500,$body),'stub-token');tagCheck(mb_strlen($safe['response_body'])===8192&&$safe['body_truncated'],'Begrenzing fout');
$escaped=App\Toggl\TagDiagnostic::response(new HttpResponse(400,'{"detail":"stub-\u0074oken","Authorization":{"value":"private"}}'),'stub-token');tagCheck(!str_contains($escaped['response_body'],'stub-token')&&!str_contains($escaped['response_body'],'private'),'Escaped credential gelekt');

// Manual retry controller: CSRF, PRG, and no coupling to invoice/add.
$temp=sys_get_temp_dir().'/tag-sync-'.bin2hex(random_bytes(8));mkdir($temp);file_put_contents($temp.'/.env',"APP_NAME=Test\n");$config=new Config($temp);$view=new View(dirname(__DIR__).'/templates');$f=tagFixture(1);$calls=0;
try{
 TogglSession::open($config);$sessionId=session_id();$csrf=$_SESSION['csrf'];session_write_close();
 $controller=new HistoryController($config,$view,$f['repo'],function($id,$retry)use(&$calls,$f){$calls++;tagCheck($retry===true,'Geen expliciete retry');return $f['sync']->sync($id,true);});
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>'invalid','action'=>'sync_tags','invoice_id'=>'1'];ob_start();$controller->handle();$html=ob_get_clean();tagCheck(http_response_code()===403&&$calls===0&&str_contains($html,'Tags zijn niet gewijzigd'),'CSRF niet beschermd');
 $_POST=['csrf'=>$csrf,'action'=>'sync_tags','invoice_id'=>'1'];ob_start();$controller->handle();$html=ob_get_clean();tagCheck(http_response_code()===303&&$calls===1&&$html==='','Retry heeft geen PRG');
 $_SERVER['REQUEST_METHOD']='GET';$_POST=[];ob_start();$controller->handle();$html=ob_get_clean();tagCheck(str_contains($html,'1/1 gesynchroniseerd')&&!str_contains($html,'Toggl-tags opnieuw synchroniseren'),'Syncstatus/knop onjuist');
 $failureController=new HistoryController($config,$view,$f['repo'],function(){throw new RuntimeException('HTTP 500 Invalid path /api/v9 private');});
 $_SERVER['REQUEST_METHOD']='POST';$_POST=['csrf'=>$csrf,'action'=>'sync_tags','invoice_id'=>'1'];ob_start();$failureController->handle();ob_end_clean();
 $_SERVER['REQUEST_METHOD']='GET';$_POST=[];ob_start();$failureController->handle();$failureHtml=ob_get_clean();
 tagCheck(str_contains($failureHtml,'De Toggl-tags konden niet volledig worden bijgewerkt')&&str_contains($failureHtml,'De factuur blijft succesvol geregistreerd')&&!str_contains($failureHtml,'Invalid path')&&!str_contains($failureHtml,'HTTP 500'),'Functionele foutmelding ontbreekt of lekt techniek');
}finally{if(session_status()===PHP_SESSION_ACTIVE){session_write_close();}if(isset($sessionId)&&is_file(dirname(__DIR__).'/storage/sess_'.$sessionId)){unlink(dirname(__DIR__).'/storage/sess_'.$sessionId);}unlink($temp.'/.env');rmdir($temp);}
echo "Tagsync-tests geslaagd: PUT, behoud tags, historische tags, gedeeltelijk/volledig falen, lokale autoriteit, retry, quota, lifecycle, CSRF/PRG en credentials.\n";
