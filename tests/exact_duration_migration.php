<?php
declare(strict_types=1);
use App\Database\Migrator;
require dirname(__DIR__).'/vendor/autoload.php';

function migrationCheck(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function oldDurationDatabase():PDO{
    $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE migrations(version TEXT PRIMARY KEY,applied_at TEXT NOT NULL)');
    foreach(['001_foundation.sql','002_wefact_debtors.sql','003_project_mappings.sql','004_invoice_registration.sql']as$file){
        $pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/'.$file));
        $q=$pdo->prepare('INSERT INTO migrations VALUES(?,?)');$q->execute([$file,gmdate('c')]);
    }
    return $pdo;
}
$pdo=oldDurationDatabase();
foreach(['prepared','reconciliation_required']as$state){
    $q=$pdo->prepare('INSERT INTO invoice_actions(token_hash,action_fingerprint,owner_hash,dataset_id,toggl_project_id,source_fingerprint,preview_hash,snapshot_json,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$state,$state,'owner','dataset',1,'source','old','{"entries":[{"duration":360}],"billable_duration_seconds":600}',$state,'test','test']);
}
$pdo->exec("INSERT INTO invoice_entry_reservations VALUES(123,'reconciliation_required')");
$m=new Migrator($pdo,dirname(__DIR__).'/database/migrations');
migrationCheck($m->migrate()===8 && $m->migrate()===0,'005 niet eenmalig/herhaalbaar');
foreach(['invoices','invoiced_entries']as$table){
    $columns=$pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1);
    migrationCheck(in_array('duration_seconds',$columns,true)&&!in_array('billable_duration_seconds',$columns,true)&&!in_array('actual_duration_seconds',$columns,true),'Durationkolommen niet vereenvoudigd');
}
migrationCheck($pdo->query("SELECT state FROM invoice_actions WHERE token_hash='prepared'")->fetchColumn()==='cancelled','Oude bevestiging nog actief');
migrationCheck($pdo->query("SELECT state FROM invoice_actions WHERE token_hash='reconciliation_required'")->fetchColumn()==='reconciliation_required','Onzekere actie gewijzigd');
migrationCheck((int)$pdo->query('SELECT count(*) FROM invoice_entry_reservations')->fetchColumn()===1,'Onzekere reservering verwijderd');
// Refuse an unexpected already-rounded successful history instead of silently rewriting its meaning.
$guard=oldDurationDatabase();
$guard->exec("INSERT INTO invoice_actions(token_hash,action_fingerprint,owner_hash,dataset_id,toggl_project_id,source_fingerprint,preview_hash,snapshot_json,state,created_at,updated_at) VALUES('old','old','owner','dataset',1,'source','old','{}','succeeded','test','test')");
$guard->exec("INSERT INTO invoices(token_hash,toggl_project_id,project_name,wefact_debtor_id,debtor_name,wefact_source_fingerprint,wefact_invoice_id,wefact_invoice_code,wefact_status,invoice_date,period_from,period_to,hourly_rate_cents,actual_duration_seconds,billable_duration_seconds,amount_excl_cents,line_mode,lines_json,created_at) VALUES('old',1,'A','42','Example Client','source','12','[concept]12',0,'2026-10-13','2026-10-10','2026-10-10',8500,60,300,708,'summary','[]','test')");
$refused=false;
try{(new Migrator($guard,dirname(__DIR__).'/database/migrations'))->migrate();}catch(PDOException){$refused=true;}
migrationCheck($refused,'Afgeronde historie zonder controle gewijzigd');
migrationCheck((int)$guard->query('SELECT count(*) FROM invoices')->fetchColumn()===1,'Historie verloren');
migrationCheck(in_array('billable_duration_seconds',$guard->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_COLUMN,1),true),'Migration niet teruggerold');
echo "Exact-duration-migration geslaagd: 004→005, schema, herhaling, vervallen bevestigingen, behouden reserveringen en rollback bij afgeronde historie.\n";
