<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Invoice\{LineCalculator,PreviewException};
function quarterCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function quarterEntry(int $id,int $seconds,string $description):array{return ['id'=>$id,'duration'=>$seconds,'description'=>$description];}
$c=new LineCalculator();
foreach([0=>0,1=>900,899=>900,900=>900,901=>1800,99900=>99900,99901=>100800,98327=>99000,100800=>100800]as$actual=>$billed){
 $m=$c->calculate([quarterEntry(1,$actual,'A')],10000,'summary','Example Project');
 quarterCheck($m['duration_seconds']===$actual&&$m['entries'][0]['duration']===$actual&&$m['billed_duration_seconds']===$billed,'Kwartiergrens of auditduur fout');
 quarterCheck($m['total_cents']===intdiv($billed,900)*2500,'Projectbedrag fout');
}
$m=$c->calculate([quarterEntry(1,360,'A'),quarterEntry(2,360,'B')],10000,'description','Example Project');
quarterCheck($m['billed_duration_seconds']===900&&array_column($m['lines'],'billed_duration_seconds')===[900,0],'Entries/groepen afzonderlijk afgerond of tie-breaker fout');
quarterCheck(array_column($m['lines'],'duration_seconds')===[360,360]&&array_sum(array_column($m['lines'],'amount_cents'))===2500,'Werkelijke regeltijd/audit verloren');
foreach([1,7,59,60,899,900,901,98327]as$seconds){
 $entries=[quarterEntry(1,$seconds,'z'),quarterEntry(2,$seconds*2,'a'),quarterEntry(3,0,'zero')];
 $summary=$c->calculate($entries,8501,'summary','A');$detail=$c->calculate($entries,8501,'description','A');
 quarterCheck($summary['total_cents']===$detail['total_cents']&&array_sum(array_column($detail['lines'],'amount_cents'))===$summary['total_cents'],'Cententotalen verschillen');
 quarterCheck(array_sum(array_column($detail['lines'],'billed_duration_seconds'))===$summary['billed_duration_seconds'],'Zichtbare minuten tellen niet op');
 quarterCheck(array_sum(array_column($detail['lines'],'duration_seconds'))===$summary['duration_seconds'],'Exacte regelseconden verloren');
 $reverse=$c->calculate(array_reverse($entries),8501,'description','A');
 $byName=static function($model){$r=[];foreach($model['lines']as$l){$r[$l['description']]=[$l['billed_duration_seconds'],$l['amount_cents']];}ksort($r);return $r;};
 quarterCheck($byName($reverse)===$byName($detail),'Verdeling hangt af van selectievolgorde');
}
try{$c->calculate([quarterEntry(1,PHP_INT_MAX,'A')],10000,'summary','A');throw new RuntimeException('Overflow geaccepteerd');}catch(PreviewException){}
echo "Kwartier-tests geslaagd: grenzen, projectafronding, exacte auditseconden, minuut-/centverdeling, nulduur, volgorde en overflow.\n";

foreach([90000=>'25',90900=>'25.25',91800=>'25.5',92700=>'25.75',0=>'0']as$seconds=>$number){
 quarterCheck(App\Invoice\ApiDecimal::quarterHours($seconds)===$number,'Kwartieruren niet compact');
 $wire=App\WeFact\JsonPayload::encode(['Number'=>$number]);quarterCheck($wire==='{"Number":'.$number.'}','Compacte numerieke wirewaarde fout');
}
$meta=['hourly_rate_cents'=>8502,'project_name'=>'A','mode'=>'description','wefact_debtor_id'=>'42','from'=>'2026-10-01','to'=>'2026-10-14'];
$two=[quarterEntry(1,900,'a'),quarterEntry(2,900,'b')];
$detail=$c->calculate($two,8502,'description','A')+$meta;
$summary=$c->calculate($two,8502,'summary','A')+array_replace($meta,['mode'=>'summary']);
quarterCheck($detail['total_cents']===4251&&$summary['total_cents']===4251,'Project half-up bij fracties van centen fout');
quarterCheck(App\Invoice\ApiDecimal::roundedLine('0.25',8502)*2===4252,'Restcenttegenvoorbeeld fout');
try{(new App\Invoice\InvoicePayload())->build($detail,'2026-10-14');throw new RuntimeException('Onveilige restcentpayload toegelaten');}catch(App\Invoice\InvoiceException $e){quarterCheck(str_contains($e->getMessage(),'Samengevat'),'Geen bruikbare restcentmelding');}
$payload=(new App\Invoice\InvoicePayload())->build($summary,'2026-10-14');
quarterCheck($payload['InvoiceLines'][0]['Number']==='0.5'&&$payload['InvoiceLines'][0]['PriceExcl']==='85.02','Samengevat exact tarief/uren fout');
foreach([8500,8501,8502,8503]as$rate){
 $model=$c->calculate([quarterEntry(1,900,'a')],$rate,'summary','A')+array_replace($meta,['mode'=>'summary','hourly_rate_cents'=>$rate]);
 $p=(new App\Invoice\InvoicePayload())->build($model,'2026-10-14');
 quarterCheck(App\Invoice\ApiDecimal::roundedLine($p['InvoiceLines'][0]['Number'],$rate)===$model['total_cents'],'Kwartier met fractiecenten niet exact gevalideerd');
}

// Coordinated quarter allocation minimizes squared deviation from actual group times.
$actual=[1000,701,99];$groups=[];foreach($actual as$i=>$seconds){$groups[]=quarterEntry($i,$seconds,chr(97+$i));}
$m=$c->calculate($groups,10000,'description','A');$allocated=array_column($m['lines'],'billed_duration_seconds');
$cost=static fn($values)=>array_sum(array_map(static fn($a,$b)=>($a-$b)*($a-$b),$values,$actual));
$best=$cost($allocated);$q=intdiv($m['billed_duration_seconds'],900);
for($a=0;$a<=$q;$a++){for($b=0;$b<=$q-$a;$b++){quarterCheck($best<=$cost([$a*900,$b*900,($q-$a-$b)*900]),'Kwartierverdeling niet dichtst bij werkelijke tijd');}}
