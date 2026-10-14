<?php
declare(strict_types=1);
// Names, remote IDs and invoice references below are synthetic fixtures.
use App\View\{Duration,View};
use App\Invoice\{LineCalculator,InvoicePayload,InvoiceRepository,ApiDecimal};
require dirname(__DIR__).'/vendor/autoload.php';
function timeUiCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
foreach([0=>'0:00',1=>'0:00',59=>'0:00',60=>'0:01',360=>'0:06',3780=>'1:03',98327=>'27:18',89999=>'24:59',864000=>'240:00']as$seconds=>$expected){
    timeUiCheck(Duration::hoursMinutes($seconds)===$expected,'Uren:minuten-weergave fout');
}
$entry=['id'=>1,'project_id'=>1,'workspace_id'=>100,'description'=>'Werk','duration'=>98327,'start'=>'2026-10-10T10:00:00Z','stop'=>'2026-10-11T13:18:47Z','tags'=>[]];
$model=(new LineCalculator())->calculate([$entry],8500,'summary','Example Project')+[
    'hourly_rate_cents'=>8500,'toggl_project_id'=>1,'project_name'=>'Example Project','wefact_debtor_id'=>'42','wefact_display_name'=>'Example Client',
    'debtor_number'=>'DB42','mode'=>'summary','entry_ids'=>[1],'from'=>'2026-10-10','to'=>'2026-10-11','complete'=>true,
];
$payload=(new InvoicePayload())->build($model,'2026-10-13');
$hash=InvoiceRepository::modelHash($model);$before=$payload;
timeUiCheck($model['duration_seconds']===98327&&$model['total_cents']===233750,'Seconden niet volledig in bedrag meegenomen');
timeUiCheck($payload['InvoiceLines'][0]['Number']==='27.5'&&$payload['InvoiceLines'][0]['PriceExcl']==='85.00','Exact regelbedrag niet doorgegeven');
timeUiCheck($payload['InvoiceLines'][0]['Description']==='Werkzaamheden Example Project'&&$payload['InvoiceLines'][0]['NumberSuffix']===' uur','Omschrijving of eenheid fout');
$exampleModel=(new LineCalculator())->calculate([$entry],12000,'summary','Example Project')+array_replace($model,['project_name'=>'Example Project','hourly_rate_cents'=>12000]);
$examplePayload=(new InvoicePayload())->build($exampleModel,'2026-10-13');
timeUiCheck($examplePayload['InvoiceLines'][0]['Number']==='27.5'&&$examplePayload['InvoiceLines'][0]['PriceExcl']==='120.00'&&$examplePayload['InvoiceLines'][0]['Description']==='Werkzaamheden Example Project','Example Project voorbeeld niet exact/menselijk');
$bad=$exampleModel;$bad['total_cents']++;
try{(new InvoicePayload())->build($bad,'2026-10-13');throw new RuntimeException('Afwijkende regeltotalen geaccepteerd');}catch(\App\Invoice\InvoiceException){}
ob_start();
(new View(dirname(__DIR__).'/templates'))->page('Test','Bevestiging','','/concept-bevestigen',[
    'model'=>$model,'payload'=>$payload,'action'=>['state'=>'prepared'],'csrf'=>'test','token'=>'test','error'=>null,
]);
$html=ob_get_clean();
timeUiCheck(str_contains($html,'<td>27:30</td>')&&!str_contains($html,'27.313055555555')&&str_contains($html,'27:18:47'),'Bevestigingsregel toont technische waarde/seconden');
timeUiCheck(str_contains($html,'€ 2337,50')&&str_contains($html,'€ 85,00'),'NL-bedragen of exacte bedrag fout');
timeUiCheck($hash===InvoiceRepository::modelHash($model)&&$before===$payload,'Presentatie wijzigde model/payload');
$history=['invoices'=>[[
    'invoice_date'=>'2026-10-13','project_name'=>'Example Project','debtor_name'=>'Example Client','wefact_invoice_code'=>'[concept]test',
    'wefact_invoice_id'=>'12','period_from'=>'2026-10-10','period_to'=>'2026-10-11','duration_seconds'=>98327,'amount_excl_cents'=>232161,
]],'uncertain'=>[]];
ob_start();
(new View(dirname(__DIR__).'/templates'))->page('Test','Historie','','/facturen',['history'=>$history,'message'=>null,'error'=>null]);
$html=ob_get_clean();
timeUiCheck(str_contains($html,'<td>27:18<small>Werkelijk: 27:18:47</small></td>')&&str_contains($html,'€ 2321,61'),'Historie toont geen menselijke/exacte waarde');
// Closed actions and their diagnostics are hidden; successful invoices and open warnings remain.
$history['invoices'][0]['project_name']='Example Project';
$history['invoices'][0]['wefact_invoice_code']='EXAMPLE-001';$history['invoices'][0]['wefact_invoice_id']='91001';
$closed=['state'=>'cancelled','outcome'=>'rejected','created_at'=>'2026-10-12T09:20:00+00:00',
    'snapshot_json'=>json_encode(['project_name'=>'Example Project','total_cents'=>327757,'duration_seconds'=>98327,'entry_ids'=>[1]]),
    'message'=>'Oude poging afgewezen <veilig>',
    'diagnostics_json'=>json_encode(['api_errors'=>['IgnoreDiscount ongeldig'],'http_status'=>200])];
$history['failed']=[$closed];
$render=static function(array $history):string {
    ob_start();(new View(dirname(__DIR__).'/templates'))->page('Test','Historie','','/facturen',['history'=>$history,'message'=>null,'error'=>null]);return ob_get_clean();
};
$html=$render($history);
timeUiCheck(str_contains($html,'>Facturen</h2>')&&str_contains($html,'EXAMPLE-001 / 91001')&&!str_contains($html,'Afgesloten factuuracties'),'Factuur ontbreekt of afgesloten sectie zichtbaar');
timeUiCheck(!str_contains($html,'id="open-actions-title"'),'Lege sectie handmatige controle zichtbaar');
timeUiCheck(!str_contains($html,'Mislukte factuuractie')&&!str_contains($html,'IgnoreDiscount ongeldig')&&!str_contains($html,'Oude poging afgewezen'),'Afgesloten audit lekt naar UI');
$history['uncertain']=[array_replace($closed,['state'=>'reconciliation_required','outcome'=>'unknown'])];
$html=$render($history);
timeUiCheck(strpos($html,'id="concept-invoices-title"')<strpos($html,'id="open-actions-title"')&&!str_contains($html,'id="closed-actions-title"')&&str_contains($html,'role="alert"'),'Open actie niet zichtbaar in juiste sectie');
$history['uncertain']=[array_replace($closed,['state'=>'cancelled','outcome'=>'created_review'])];
$html=$render($history);
timeUiCheck(!str_contains($html,'id="open-actions-title"')&&str_contains($html,'WeFact-aanmaak bevestigd · lokale controle vereist')&&str_contains($html,'entries blijven geblokkeerd'),'Bestaande created-review-waarschuwing verloren');
echo "Tijdpresentatie geslaagd: uren:minuten, >24 uur, seconden behouden, bevestiging/historie en ongewijzigde API-payload/centen.\n";
