<?php
declare(strict_types=1);
// Names, remote IDs and invoice references below are synthetic fixtures.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Database\{Database,Maintenance,Migrator,AdministrationException};
use App\Invoice\{InvoiceRepository,Reconciliation,LocalInvoiceStatus};
use App\Toggl\TimeEntryStatus;
function recoverCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function recoverReject(callable $f):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Weigering verwacht');}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/recovery-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/app.sqlite';$migrations=$root.'/database/migrations';
try{
 recoverReject(fn()=>Database::connect($path));recoverCheck(!file_exists($path),'Dagelijks starten maakt ontbrekende DB aan');
 Maintenance::initialize($path,$migrations);$p=Database::connect($path);Maintenance::validate($p,$migrations,true);
 recoverCheck((fileperms($path)&0777)===0600,'Database niet owner-only');recoverReject(fn()=>Maintenance::initialize($path,$migrations));
 $backup=Maintenance::backup($path,$dir.'/backups',$migrations);recoverCheck((fileperms($backup)&0777)===0600&&(fileperms($dir.'/backups')&0777)===0700,'Backuprechten fout');Maintenance::validate(Maintenance::readOnly($backup),$migrations);
 file_put_contents($dir.'/invalid','geen database');recoverReject(fn()=>Maintenance::restore($dir.'/invalid',$dir.'/invalid-target',$migrations));recoverCheck(!file_exists($dir.'/invalid-target'),'Ongeldige restore liet database achter');
 $foreign=new PDO('sqlite:'.$dir.'/foreign.sqlite');$foreign->exec('CREATE TABLE other(id INTEGER)');unset($foreign);recoverReject(fn()=>Maintenance::restore($dir.'/foreign.sqlite',$dir.'/foreign-target',$migrations));
 $hash=hash_file('sha256',$path);recoverReject(fn()=>Maintenance::restore($backup,$path,$migrations));recoverCheck(hash_file('sha256',$path)===$hash,'Bestaande database overschreven');
 Maintenance::restore($backup,$dir.'/restored.sqlite',$migrations);Maintenance::validate(Database::connect($dir.'/restored.sqlite'),$migrations,true);
 // A backup remains consistent while another connection has an uncommitted write.
 $p->beginTransaction();$p->exec("INSERT INTO api_cache(cache_key,payload,expires_at,created_at) VALUES('pending','{}','later','now')");
 $concurrent=Maintenance::backup($path,$dir.'/backups',$migrations);$p->rollBack();
 recoverCheck((int)Maintenance::readOnly($concurrent)->query("SELECT count(*) FROM api_cache WHERE cache_key='pending'")->fetchColumn()===0,'Backup bevat niet-gecommitte data');
 $repo=new InvoiceRepository($p);$entry=['id'=>123,'project_id'=>1,'workspace_id'=>100,'duration'=>360,'description'=>'Werk','start'=>'2026-10-10T10:00:00Z','stop'=>'2026-10-10T10:06:00Z','tags'=>[]];
 $model=['toggl_project_id'=>1,'project_name'=>'Example Project','wefact_debtor_id'=>'42','wefact_display_name'=>'Example Client','duration_seconds'=>360,'billed_duration_seconds'=>900,'hourly_rate_cents'=>10000,'total_cents'=>2500,'mode'=>'summary','lines'=>[],'entries'=>[$entry],'entry_ids'=>[123],'from'=>'2026-10-10','to'=>'2026-10-10'];
 $repo->prepare('no','owner','data1','scope',$model,['Date'=>'2026-10-10']);$repo->claim('no','owner',$model,[]);$recovery=new Reconciliation($p);
 recoverReject(fn()=>$recovery->resolve(hash('sha256','no'),'no_invoice',[999],'Gecontroleerd'));
 $recovery->resolve(hash('sha256','no'),'no_invoice',[123],'Handmatig geen factuur aangetroffen');
 recoverCheck($repo->action('no','owner')['state']==='cancelled'&&(int)$p->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===0,'Vrijgave mislukt');
 recoverCheck(count($repo->history()['failed'])===1,'Afgesloten recovery onzichtbaar');recoverReject(fn()=>$recovery->resolve(hash('sha256','no'),'no_invoice',[123],'Opnieuw'));
 $repo->prepare('exists','owner','data2','scope',$model,['Date'=>'2026-10-10']);$repo->claim('exists','owner',$model,['Date'=>'2026-10-10']);
 recoverReject(fn()=>$recovery->resolve(hash('sha256','exists'),'link_existing',[123],'Concept gecontroleerd','91002','EXAMPLE-001',2400,'42'));
 $recovery->resolve(hash('sha256','exists'),'link_existing',[123],'Bestaand concept handmatig gecontroleerd','91002','EXAMPLE-001',2500,'42');
 recoverCheck($repo->action('exists','owner')['state']==='succeeded'&&(int)$p->query('SELECT count(*) FROM invoices')->fetchColumn()===1,'Bestaand concept niet lokaal geregistreerd');
 recoverCheck(TimeEntryStatus::isInvoiced((new LocalInvoiceStatus($p))->annotate([$entry])[0]),'Herstelde factuur blokkeert entries niet');
 recoverCheck((int)$p->query('SELECT count(*) FROM invoice_reconciliation_events')->fetchColumn()===2,'Recovery-audit ontbreekt');
 recoverReject(fn()=>$recovery->resolve(hash('sha256','exists'),'no_invoice',[123],'Onterecht vrijgeven'));
 // Bootstrap opens an existing current administration without running migrations.
 $pipes=[];$proc=proc_open([PHP_BINARY,'-r','require $argv[1]; echo "ready";',$root.'/bootstrap.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,['DB_PATH'=>$path]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);recoverCheck(proc_close($proc)===0&&str_contains($out,'ready'),'Dagelijkse bootstrap met bestaande DB mislukt');
 $missing=$dir.'/missing.sqlite';$pipes=[];$proc=proc_open([PHP_BINARY,$root.'/public/index.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,['DB_PATH'=>$missing]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);recoverCheck(str_contains($out,'administratie ontbreekt')&&!file_exists($missing),'Webstart maakt DB aan of mist herstelmelding');
 file_put_contents($dir.'/unsafe.sqlite-wal','old');recoverReject(fn()=>Maintenance::restore($backup,$dir.'/unsafe.sqlite',$migrations));recoverCheck(!file_exists($dir.'/unsafe.sqlite'),'Restore met oude WAL toegestaan');
 // Pending migrations block normal startup rather than silently applying changes.
 $p->exec("DELETE FROM migrations WHERE version='012_manual_reconciliation.sql'");recoverReject(fn()=>Maintenance::validate($p,$migrations,true));
 // CLI failure output must never echo raw database errors or secrets.
 $cmd=[PHP_BINARY,$root.'/bin/admin.php','restore','--confirm-restore','--file='.$dir.'/private-token-missing'];
 $pipes=[];$process=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,['DB_PATH'=>$path]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);recoverCheck(proc_close($process)!==0&&!str_contains($out,'private-token'),'CLI lekt foutinput');
}finally{unset($p);$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as$file){if($file->isDir()){rmdir($file->getPathname());}else{unlink($file->getPathname());}}rmdir($dir);}
echo "Recovery-tests geslaagd: fail-closed start, init, consistente backup, restorevalidatie/no-clobber, rechten, reconciliation en veilige output.\n";
