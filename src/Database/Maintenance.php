<?php
declare(strict_types=1);
namespace App\Database;
use PDO;
use Throwable;

/** Validates and preserves the local administration through explicit offline maintenance. */
final class Maintenance
{
    /**
     * Compare integrity and schema against the ordered, known migration prefix.
     * Allows an older valid database for backup; current=true requires all known migrations.
     * @throws AdministrationException On damaged, inconsistent, unknown or disallowed older schema.
     */
    public static function validate(PDO $pdo,string $migrations,bool $current=false): void
    {
        try{
            if($pdo->query('PRAGMA integrity_check')->fetchColumn()!=='ok'||$pdo->query('PRAGMA foreign_key_check')->fetchAll()!==[]){throw new \RuntimeException();}
            $files=glob($migrations.'/*.sql');sort($files);$known=array_map('basename',$files);
            $versions=$pdo->query('SELECT version FROM migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
            if($versions===[]||$versions!==array_slice($known,0,count($versions))){throw new \RuntimeException();}
            if($current&&$versions!==$known){throw new AdministrationException('Database-update nodig. Maak eerst een back-up en voer composer migrate uit.');}
            $expected=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $expected->exec('CREATE TABLE migrations(version TEXT PRIMARY KEY,applied_at TEXT NOT NULL)');
            foreach($versions as$v){$expected->exec(file_get_contents($migrations.'/'.$v));}
            foreach($expected->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN)as$table){
                foreach(['table_info','foreign_key_list','index_list']as$pragma){
                    $read=static function(PDO $db)use($pragma,$table):array{
                        $rows=$db->query('PRAGMA '.$pragma.'("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
                        if($pragma==='index_list'){
                            foreach($rows as&$r){
                                unset($r['seq']);
                                $r['columns']=$db->query('PRAGMA index_info('.$db->quote($r['name']).')')->fetchAll(PDO::FETCH_ASSOC);
                                $q=$db->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND name=?");$q->execute([$r['name']]);
                                $r['definition']=preg_replace('/\s+/',' ',trim((string)$q->fetchColumn()));
                            }unset($r);usort($rows,fn($a,$b)=>strcmp($a['name'],$b['name']));
                        }
                        return $rows;
                    };
                    if($read($pdo)!==$read($expected)){throw new \RuntimeException();}
                }
            }
        }catch(AdministrationException $e){throw $e;}catch(Throwable){throw new AdministrationException('De administratie is ongeldig of beschadigd. Herstel een gecontroleerde applicatieback-up; er worden geen API-acties uitgevoerd.');}
    }
    private static function directory(string $path):void
    {
        if(!is_dir($path)&&!mkdir($path,0700,true)){throw new AdministrationException('Lokale map kon niet worden aangemaakt.');}
    }
    /**
     * Create an administration exclusively; never use this as recovery for a missing live database.
     */
    public static function initialize(string $path,string $migrations):void
    {
        foreach(['-wal','-shm','-journal']as$suffix){if(file_exists($path.$suffix)){throw new AdministrationException('SQLite-nevenbestanden aanwezig; initialisatie geweigerd.');}}
        self::directory(dirname($path));$file=@fopen($path,'x');
        if($file===false){throw new AdministrationException('Initialisatie geweigerd: database bestaat al of kan niet exclusief worden aangemaakt.');}
        fclose($file);chmod($path,0600);
        try{$pdo=Database::connect($path);(new Migrator($pdo,$migrations))->migrate();self::validate($pdo,$migrations,true);}
        catch(Throwable $e){unset($pdo);unlink($path);throw $e;}
    }
    /**
     * Create and validate a consistent SQLite snapshot using VACUUM INTO, including committed WAL data.
     * Credentials are not copied; the returned file still contains sensitive administration data.
     * @return string Path to the newly created private backup file.
     */
    public static function backup(string $source,string $directory,string $migrations):string
    {
        $pdo=Database::connect($source);self::validate($pdo,$migrations);
        self::directory($directory);
        $target=rtrim($directory,'/').'/administratie-'.date('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(3)).'.sqlite';
        // VACUUM INTO creates a consistent SQLite snapshot, including concurrent committed data.
        $old=umask(0077);
        try{$pdo->exec('VACUUM INTO '.$pdo->quote($target));chmod($target,0600);$backup=self::readOnly($target);self::validate($backup,$migrations);}
        catch(Throwable $e){if(is_file($target)){unlink($target);}throw $e;}finally{umask($old);}
        return $target;
    }
    public static function readOnly(string $path):PDO
    {
        if(!is_file($path)){throw new AdministrationException('Back-upbestand ontbreekt.');}
        return new PDO('sqlite:file:'.str_replace('%2F','/',rawurlencode($path)).'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    /**
     * Validate and stage a backup, then publish it atomically without overwriting an existing target.
     * The operator must stop database users and quarantine the old database and sidecars first.
     * This restores local evidence only; remote invoices created after the backup still need review.
     * @throws AdministrationException If the backup/schema or destination is unsafe.
     */
    public static function restore(string $backup,string $target,string $migrations):void
    {
        if(file_exists($target)||is_link($target)){throw new AdministrationException('Herstel geweigerd: doel bestaat. Stop de app en zet de bestaande database eerst veilig apart.');}
        foreach(['-wal','-shm','-journal']as$suffix){if(file_exists($target.$suffix)){throw new AdministrationException('SQLite-nevenbestanden aanwezig; zet deze bij de oude administratie apart.');}}
        $pdo=self::readOnly($backup);self::validate($pdo,$migrations);
        self::directory(dirname($target));$stage=$target.'.restore-'.bin2hex(random_bytes(8));$old=umask(0077);
        try{
            $pdo->exec('VACUUM INTO '.$pdo->quote($stage));chmod($stage,0600);self::validate(self::readOnly($stage),$migrations);
            // Atomic no-clobber publication on macOS: link fails if target appeared meanwhile.
            if(!@link($stage,$target)){throw new AdministrationException('Herstel geweigerd: doel bestaat inmiddels of is niet schrijfbaar.');}
        }finally{if(is_file($stage)){unlink($stage);}umask($old);}
    }
}
