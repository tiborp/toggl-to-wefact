<?php
declare(strict_types=1);
namespace App\WeFact {
    // Exercise the real transport's wire format without opening a socket.
    function curl_init(string $url): object { $GLOBALS['status_wire_url']=$url; return new \stdClass(); }
    function curl_setopt_array(object $handle, array $options): bool { $GLOBALS['status_wire_options']=$options; return true; }
    function curl_exec(object $handle): string { return '{"status":"success"}'; }
    function curl_getinfo(object $handle, int $option): int|float { return $option === CURLINFO_RESPONSE_CODE ? 200 : 0; }
    function curl_errno(object $handle): int { return 0; }
    function curl_error(object $handle): string { return ''; }
}
namespace {
use App\WeFact\{WeFactClient, InvoiceReadTransport, HttpResponse, WeFactException, InvoiceStatusSnapshot, CurlTransport};
use App\Invoice\{RemoteStatusRepository, RemoteStatusService, InvoiceRepository, HistoryController, Specification};
use App\Database\Migrator;
use App\Config\Config;
use App\View\View;
use App\Toggl\TogglSession;
require dirname(__DIR__).'/vendor/autoload.php';
function statusCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
final class StatusReadStub implements InvoiceReadTransport {
    public array $ids=[];
    public function __construct(public array $responses=[]) {}
    public function listDebtors(#[\SensitiveParameter] string $key, int $offset, int $limit): HttpResponse { throw new RuntimeException('Unexpected debtor read'); }
    public function showInvoice(#[\SensitiveParameter] string $key, string $identifier): HttpResponse {
        $this->ids[]=$identifier; $next=array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        if (!$next instanceof HttpResponse) { throw new RuntimeException('Unexpected extra request'); }
        return $next;
    }
}
function statusResponse(int $status=0, array $overrides=[], string $id='91001'): HttpResponse {
    return new HttpResponse(200,json_encode(['controller'=>'invoice','action'=>'show','status'=>'success','invoice'=>array_replace([
        'Identifier'=>$id,'Status'=>$status,'InvoiceCode'=>'EXAMPLE-NEW','Sent'=>0,'SentDate'=>'','Date'=>'2026-10-01',
        'AmountIncl'=>'30.25','AmountPaid'=>'0.00','AmountOutstanding'=>'30.25','Currency'=>'EUR','PayDate'=>'','PayBefore'=>'2026-10-15'
    ],$overrides)],JSON_THROW_ON_ERROR));
}
function statusParse(HttpResponse $response): array { return (new WeFactClient('fake-secret',new StatusReadStub([$response])))->invoiceStatus('91001'); }
function statusFailure(HttpResponse $response, ?string $kind=null): void {
    try { statusParse($response); }
    catch (WeFactException $e) {
        statusCheck($kind === null || $e->kind === $kind,'Wrong error classification');
        statusCheck(!str_contains($e->getMessage(),'fake-secret') && $e->getPrevious()===null,'Unsafe exception'); return;
    }
    throw new RuntimeException('Invalid response accepted');
}
foreach ([0=>'Concept',2=>'Verzonden',3=>'Deels betaald',4=>'Betaald',8=>'Creditfactuur',9=>'Vervallen',77=>'Onbekend'] as $code=>$label) {
    $snapshot=statusParse(statusResponse($code));
    statusCheck($snapshot['status_code']===$code && InvoiceStatusSnapshot::label($code)===$label,'Official status mapping changed');
}
foreach ([null,'','bad',[],1.5,-1] as $sent) {
    statusCheck(statusParse(statusResponse(2,['Sent'=>$sent]))['sent_count']===null,'Unusable sent count must stay unknown');
}
$paid=statusParse(statusResponse(4,['Sent'=>0,'PayDate'=>'2026-10-12']));
statusCheck($paid['status_code']===4 && $paid['sent_count']===0 && $paid['paid_on']==='2026-10-12','Payment incorrectly implies sending');
$sent=statusParse(statusResponse(2,['Sent'=>'2','SentDate'=>'2026-10-12 11:12:13']));
statusCheck($sent['sent_count']===2 && $sent['sent_at']==='2026-10-12 11:12:13','Valid send observation lost');
foreach (['','2026-02-30 10:00:00','not-a-date','2026-01-01 25:00:00','0000-00-00 00:00:00',null] as $date) {
    statusCheck(statusParse(statusResponse(2,['SentDate'=>$date]))['sent_at']===null,'Invalid/missing send date accepted');
}
$minimal = new HttpResponse(200,'{"controller":"invoice","action":"show","status":"success","invoice":{"Identifier":91001,"Status":2,"InvoiceCode":"EXAMPLE-NEW"}}');
$m=statusParse($minimal);
foreach (['sent_count','sent_at','sub_status','invoice_date','amount_incl_cents','amount_paid_cents','amount_outstanding_cents','currency','paid_on','pay_before'] as $field) {
    statusCheck($m[$field]===null,'Optional field not nullable: '.$field);
}
$money=statusParse(new HttpResponse(200,'{"controller":"invoice","action":"show","status":"success","invoice":{"Identifier":91001,"Status":8,"InvoiceCode":"EXAMPLE-CREDIT","AmountIncl":90071992547409.01,"AmountPaid":0,"AmountOutstanding":-123.45}}'));
statusCheck($money['amount_incl_cents']===9007199254740901 && $money['amount_paid_cents']===0 && $money['amount_outstanding_cents']===-12345,'Money lost exact cents');
foreach ([['Identifier'=>91002],['Status'=>null],['Status'=>'bad'],['InvoiceCode'=>[]],['AmountIncl'=>'1.001'],['AmountPaid'=>[]],['AmountOutstanding'=>'9223372036854775808'],['Currency'=>[]],['SubStatus'=>[]]] as $bad) {
    statusFailure(statusResponse(2,$bad),'response_validation');
}
foreach (['bad','[]','null','{"controller":"invoice","action":"add","status":"success","invoice":{}}'] as $bad) { statusFailure(new HttpResponse(200,$bad)); }
statusFailure(new HttpResponse(429,'not json'),'rate_limit');
statusFailure(new HttpResponse(401,'fake-secret'),'access');
statusFailure(new HttpResponse(200,'{"status":"error","errors":["Invalid API key fake-secret"]}'),'authentication');
statusFailure(new HttpResponse(200,'{"status":"error","errors":["Too many requests fake-secret"]}'),'rate_limit');
$redacted=statusParse(statusResponse(2,['InvoiceCode'=>'fake-secret','SubStatus'=>base64_encode('fake-secret')]));
statusCheck(!str_contains(json_encode($redacted),'fake-secret') && $redacted['invoice_code']==='[afgeschermd]' && $redacted['sub_status']==='[afgeschermd]','Echoed credential persisted');
$statusTransport=new CurlTransport(); $statusTransport->showInvoice('fake-secret','91001');
$wire=json_decode($GLOBALS['status_wire_options'][CURLOPT_POSTFIELDS],true);
statusCheck($wire===['api_key'=>'fake-secret','controller'=>'invoice','action'=>'show','Identifier'=>91001], 'Incorrect show wire payload');
statusCheck($GLOBALS['status_wire_url']==='https://api.mijnwefact.nl/v2/' && $GLOBALS['status_wire_options'][CURLOPT_FOLLOWLOCATION]===false,'Existing secure transport changed');

function statusSetup(): array {
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec('PRAGMA foreign_keys=ON'); (new Migrator($p,dirname(__DIR__).'/database/migrations'))->migrate();
    for ($i=1;$i<=5;$i++) {
        $snapshot=json_encode(['entry_ids'=>[92000+$i]]);
        $p->prepare("INSERT INTO invoice_actions(token_hash,action_fingerprint,owner_hash,dataset_id,toggl_project_id,source_fingerprint,preview_hash,snapshot_json,state,created_at,updated_at) VALUES(?,?,?,?,1,?,'preview',?,'succeeded','2026-10-01','2026-10-01')")->execute(['fixture'.$i,'fingerprint'.$i,'owner','dataset','source',$snapshot]);
        $p->prepare("INSERT INTO invoices(id,token_hash,toggl_project_id,project_name,wefact_debtor_id,debtor_name,wefact_source_fingerprint,wefact_invoice_id,wefact_invoice_code,wefact_status,invoice_date,period_from,period_to,hourly_rate_cents,duration_seconds,billed_duration_seconds,amount_excl_cents,line_mode,lines_json,created_at) VALUES(?,?,1,'Example Project','8001','Example Client',?,?,?,0,'2026-10-01','2026-09-01','2026-09-30',10000,61,900,2500,'summary','[]','2026-10-01')")->execute([$i,'fixture'.$i,$i===5?'other-source':'source',(string)(91000+$i),'EXAMPLE-OLD-'.$i]);
        $p->prepare("INSERT INTO invoiced_entries(invoice_id,toggl_time_entry_id,toggl_project_id,duration_seconds,description,start,created_at) VALUES(?,?,1,61,'Example work','2026-09-01T10:00:00Z','2026-10-01')")->execute([$i,92000+$i]);
        $p->prepare('INSERT INTO invoice_entry_reservations(toggl_time_entry_id,token_hash) VALUES(?,?)')->execute([92000+$i,'fixture'.$i]);
    }
    $p->exec("UPDATE invoices SET lifecycle='manual_deleted_test',closure_reason='Example test closed',closed_at='2026-10-02' WHERE id=4");
    return [$p,new RemoteStatusRepository($p)];
}
function statusProtected(PDO $p): array {
    $state=[];
    foreach (['invoices','invoice_actions','invoiced_entries','invoice_entry_reservations','toggl_tag_sync_attempts'] as $table) { $state[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(); }
    return $state;
}
[$pdo,$repo]=statusSetup();$before=statusProtected($pdo);
$http=new StatusReadStub([statusResponse(2),new HttpResponse(200,'malformed'),statusResponse(4,['Sent'=>0],'91003')]);
$result=(new RemoteStatusService($repo,new WeFactClient('fake-secret',$http),'source'))->refresh();
statusCheck($result===['success'=>2,'failed'=>1,'skipped'=>1,'stopped'=>false] && $http->ids===['91001','91002','91003'],'Partial refresh/candidate scoping wrong');
statusCheck(statusProtected($pdo)===$before,'Refresh changed invoice, entry, reservation or Toggl state');
$rows=$pdo->query('SELECT * FROM invoice_remote_status ORDER BY invoice_id')->fetchAll();
statusCheck(count($rows)===4 && $rows[0]['invoice_code']==='EXAMPLE-NEW' && $rows[1]['checked_at']===null && $rows[3]['last_error_kind']==='source_mismatch','Snapshot/failure metadata wrong');
$old=$rows[0];
(new RemoteStatusService($repo,new WeFactClient('fake-secret',new StatusReadStub([new WeFactException('fake-secret','timeout'),statusResponse(3,[],'91002'),statusResponse(4,[],'91003')])),'source'))->refresh();
$after=$pdo->query('SELECT * FROM invoice_remote_status WHERE invoice_id=1')->fetch();
foreach (array_keys($old) as $field) {
    if (!in_array($field,['last_attempt_at','last_attempt_result','last_error_kind'],true)) { statusCheck($after[$field]===$old[$field],'Failure damaged last snapshot: '.$field); }
}
statusCheck($after['last_attempt_result']==='failed' && $after['last_error_kind']==='timeout','Failure not recorded');
foreach ([new HttpResponse(401,'denied'),new HttpResponse(429,'limit'),new HttpResponse(200,'{"status":"error","errors":["Too many requests"]}'),new HttpResponse(200,'{"status":"error","errors":["API key invalid"]}')] as $stop) {
    [$p,$r]=statusSetup();$stub=new StatusReadStub([$stop]);
    $res=(new RemoteStatusService($r,new WeFactClient('fake-secret',$stub),'source'))->refresh();
    statusCheck($res['stopped'] && $res['failed']===1 && count($stub->ids)===1,'Auth/rate limit did not stop subsequent requests');
}
[$excluded,$excludedRepo]=statusSetup();
$excluded->exec("UPDATE invoice_actions SET state='reconciliation_required' WHERE token_hash='fixture1'");
statusCheck(array_column($excludedRepo->candidates(),'id')===[2,3,5],'Non-successful invoice action included');
$unchanged=statusProtected($pdo);
statusCheck((new Specification($pdo))->find(1)['available'],'Remote observation broke historical specification');
statusCheck(statusProtected($pdo)===$unchanged,'Historical view changed billing state');
// Presentation remains literal: status 2 never becomes a derived Openstaand label.
function statusHtml(array $remote, ?int $creationStatus=null): string { $invoice=['wefact_status'=>$creationStatus]; ob_start(); require dirname(__DIR__).'/templates/invoice_remote_status.php'; return ob_get_clean(); }
$base=$after;
foreach ([0=>'Concept',2=>'Verzonden',3=>'Deels betaald',4=>'Betaald',8=>'Creditfactuur',9=>'Vervallen',88=>'Onbekend'] as $code=>$label) {
    $html=statusHtml(array_replace($base,['status_code'=>$code,'last_attempt_result'=>'success','sent_count'=>0]));
    statusCheck(str_contains($html,$label) && str_contains($html,'Geen verzending geregistreerd') && !str_contains($html,'Openstaand'),'Wrong status/send UI');
}
statusCheck(str_contains(statusHtml(array_replace($base,['sent_count'=>null])),'Verzending onbekend'),'Unknown sending not visible');
statusCheck(str_contains(statusHtml(array_replace($base,['sent_count'=>1,'sent_at'=>'2026-10-12 11:12:13'])),'Verzending geregistreerd'),'Sending not visible');
statusCheck(str_contains(statusHtml(array_replace($base,['sent_count'=>1,'sent_at'=>'2026-10-12 11:12:13'])),'12-10-2026 11:12'),'Send date not localized');
statusCheck(!str_contains(statusHtml(array_replace($base,['sent_at'=>null])),'Verzenddatum:'),'Absent send date displayed');
statusCheck(str_contains(statusHtml([]),'nog niet gecontroleerd') && str_contains(statusHtml([]),'Bij aanmaak: Concept'),'Initial state unclear');
statusCheck(str_contains(statusHtml($base),'Laatste statuscontrole mislukt') && str_contains(statusHtml($base),'Verzonden'),'Previous status hidden after failure');

// Pending dispatch is a presentation-only decision, never a payment or lifecycle transition.
foreach ([
    [[], 0, true],
    [[], null, false],
    [[], 4, false],
    [['checked_at'=>null, 'status_code'=>null, 'last_attempt_result'=>'failed'], 0, true],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>0], 0, true],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>2, 'sent_count'=>0], 0, true],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>2, 'sent_count'=>1], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>3, 'sent_count'=>0], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>4, 'sent_count'=>0], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>8, 'sent_count'=>0], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>9, 'sent_count'=>0], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>88, 'sent_count'=>0], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>2], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>2, 'sent_count'=>null], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z', 'status_code'=>2, 'sent_count'=>'0'], 0, false],
    [['checked_at'=>'2026-10-14T12:00:00Z'], 0, false],
    [['status_code'=>2, 'sent_count'=>0], 0, false],
    [['checked_at'=>'', 'status_code'=>0], 0, false],
] as [$remote, $creationStatus, $expected]) {
    statusCheck(InvoiceStatusSnapshot::pendingDispatch($remote, $creationStatus)===$expected, 'Incorrect pending dispatch decision');
    $display=array_replace(['sent_count'=>null, 'sent_at'=>null, 'status_code'=>null], $remote);
    $html=statusHtml($display, $creationStatus);
    statusCheck(substr_count($html,'>Te versturen</span>')===($expected ? 1 : 0), 'Pending badge missing, duplicated or guessed');
}
statusCheck(InvoiceStatusSnapshot::pendingDispatch(null, 0), 'Unchecked local concept must remain actionable');
statusCheck(!InvoiceStatusSnapshot::pendingDispatch(null, null), 'Missing history must not imply pending');
statusCheck(InvoiceStatusSnapshot::pendingDispatch(array_replace($base,['status_code'=>0,'last_attempt_result'=>'failed']),0), 'Failed refresh must retain pending from last successful snapshot');
statusCheck(str_contains(statusHtml([],0),'nog niet gecontroleerd'), 'Fallback badge hid unchecked status');

$temp=sys_get_temp_dir().'/status-config-'.bin2hex(random_bytes(6));mkdir($temp,0700);
try {
    $config=new Config($temp);$view=new View(dirname(__DIR__).'/templates');$invoiceRepo=new InvoiceRepository($pdo);$calls=0;
    $controller=new HistoryController($config,$view,$invoiceRepo,refreshStatus:function()use(&$calls){$calls++;return ['success'=>1,'failed'=>0,'skipped'=>0,'stopped'=>false];});
    $_SERVER['REQUEST_METHOD']='GET';$_POST=[];http_response_code(200);
    ob_start();$controller->handle();$html=ob_get_clean();
    statusCheck($calls===0 && str_contains($html,'Status bijwerken') && str_contains($html,'EXAMPLE-NEW / 91001') && str_contains($html,'Urenspecificatie'),'History GET missing new number/link or invoked API');
    statusCheck(!str_contains($html,'fake-secret'),'Credential leaked to HTML');
    statusCheck(str_contains($html,'badge badge--pending') && $calls===0, 'Pending badge triggered refresh or missing for unchecked local concept');
    TogglSession::open($config);$csrf=$_SESSION['csrf'];session_write_close();
    $_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'refresh_status','csrf'=>'invalid'];
    ob_start();$controller->handle();$html=ob_get_clean();
    statusCheck(http_response_code()===403 && $calls===0 && str_contains($html,'Ongeldige aanvraag'),'Invalid CSRF invoked refresh');
    $_POST=['action'=>'refresh_status','csrf'=>$csrf,'invoice_id'=>'arbitrary-remote-id'];
    ob_start();$controller->handle();ob_end_clean();
    statusCheck($calls===1 && http_response_code()===303,'Valid POST did not refresh/redirect');
    statusCheck(statusProtected($pdo)===$unchanged,'Controller changed protected history');
} finally { if(session_status()===PHP_SESSION_ACTIVE){session_write_close();} rmdir($temp); }
echo "Invoice status tests passed: literal statuses, sending, exact money, snapshots, errors, isolation and local GET.\n";
}
