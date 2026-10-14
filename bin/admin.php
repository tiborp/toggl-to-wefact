<?php
declare(strict_types=1);

// Explicit offline maintenance: validate operator evidence before changing local administration.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Config\Config;
use App\Database\{Database,Maintenance,Migrator};
try{
    if(PHP_SAPI!=='cli'){throw new RuntimeException();}
    $root=dirname(__DIR__);$config=new Config($root);date_default_timezone_set($config->get('APP_TIMEZONE','Europe/Amsterdam'));umask(0077);
    $args=$argv;array_shift($args);$operation=array_shift($args);$options=[];
    foreach($args as$arg){if(!preg_match('/^--([a-z-]+)(?:=(.*))?$/D',$arg,$m)){throw new RuntimeException();}$options[$m[1]]=$m[2]??true;}
    $path=$config->databasePath();$migrations=$root.'/database/migrations';
    switch($operation){
        case 'init': if(($options['confirm-first-install']??false)!==true){throw new RuntimeException();}Maintenance::initialize($path,$migrations);echo "Eerste administratie geïnitialiseerd.\n";break;
        case 'migrate': $pdo=Database::connect($path);Maintenance::validate($pdo,$migrations);$n=(new Migrator($pdo,$migrations))->migrate();Maintenance::validate($pdo,$migrations,true);echo "Database-update gereed: $n migrations.\n";break;
        case 'backup': $directory=$options['directory']??$root.'/storage/backups';$file=Maintenance::backup($path,$directory,$migrations);echo "Back-up gemaakt: $file\n";break;
        case 'restore': if(($options['confirm-restore']??false)!==true||!is_string($options['file']??null)){throw new RuntimeException();}Maintenance::restore($options['file'],$path,$migrations);echo "Back-up hersteld. Voer zo nodig composer migrate uit vóór starten.\n";break;
        case 'actions':
            $pdo=Database::connect($path);Maintenance::validate($pdo,$migrations,true);
            foreach($pdo->query("SELECT token_hash,snapshot_json,created_at,wefact_invoice_id,wefact_invoice_code FROM invoice_actions WHERE state='reconciliation_required' ORDER BY created_at") as$row){
                $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                $safe=['action_id'=>$row['token_hash'],'created_at'=>$row['created_at'],'project'=>$snapshot['project_name'],'entries'=>$snapshot['entry_ids'],'amount_cents'=>$snapshot['total_cents'],'debtor_id'=>$snapshot['wefact_debtor_id'],'known_wefact_id'=>$row['wefact_invoice_id'],'known_invoice_code'=>$row['wefact_invoice_code']];
                $output=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);foreach(array_filter([$config->togglToken(),$config->wefactKey()])as$secret){$output=str_replace($secret,'[afgeschermd]',$output);}echo $output.PHP_EOL;
            }break;
        case 'reconcile':
            if(($options['confirm-manual-check']??false)!==true){throw new RuntimeException();}
            $pdo=Database::connect($path);Maintenance::validate($pdo,$migrations,true);
            $ids=explode(',',(string)($options['entries']??''));foreach($ids as$id){if(!preg_match('/^[1-9][0-9]{0,17}$/D',$id)){throw new RuntimeException();}}
            if(isset($options['amount-cents'])&&(!is_string($options['amount-cents'])||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',$options['amount-cents']))){throw new RuntimeException();}
            $reason=(string)($options['reason']??'');if(\App\Toggl\TagDiagnostic::redact($reason,$config->togglToken())!==$reason){throw new RuntimeException();}foreach(array_filter([$config->togglToken(),$config->wefactKey()])as$secret){if(str_contains($reason,$secret)){throw new RuntimeException();}}
            (new App\Invoice\Reconciliation($pdo))->resolve((string)($options['action-id']??''),(string)($options['resolution']??''),$ids,$reason,$options['wefact-id']??null,$options['invoice-code']??null,isset($options['amount-cents'])?(int)$options['amount-cents']:null,$options['debtor-id']??null);
            echo "Handmatige reconciliation lokaal vastgelegd. Geen API-acties uitgevoerd.\n";break;
        default:throw new RuntimeException();
    }
}catch(Throwable $e){fwrite(STDERR,"Onderhoud geweigerd of mislukt. Controleer commando, bevestiging, database/back-up, entryset en handmatige bewijsgegevens. Bestaande gegevens worden niet stilzwijgend overschreven.\n");exit(1);}
