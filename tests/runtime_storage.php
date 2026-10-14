<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

function storageCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$repository = dirname(__DIR__);
$root = sys_get_temp_dir() . '/session-storage-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    foreach (['toggl', 'wefact'] as $mode) {
        $fixture = $root . '/' . $mode;
        $relative = $mode === 'toggl' ? 'src/Toggl/TogglSession.php' : 'src/WeFact/StatusController.php';
        mkdir(dirname($fixture . '/' . $relative), 0700, true);
        copy($repository . '/' . $relative, $fixture . '/' . $relative);
        // Load the real session entry point from a clone-shaped tree without storage/.
        // Its dependencies use the existing autoloader; no API or real database is involved.
        $code = <<<'PHP'
require $argv[2] . '/vendor/autoload.php';
$root = $argv[1];
$config = new App\Config\Config($root);
if ($argv[3] === 'toggl') {
    require $root . '/src/Toggl/TogglSession.php';
    App\Toggl\TogglSession::open($config);
    session_write_close();
} else {
    require $root . '/src/WeFact/StatusController.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    (new App\WeFact\StatusController($config, new App\View\View($argv[2] . '/templates'), new App\WeFact\DebtorCache(new PDO('sqlite::memory:'))))->handle();
    ob_end_clean();
}
if (!is_file($root . '/storage/sess_' . session_id())) { throw new RuntimeException('Session not persisted'); }
try {
    App\Database\Database::connect($root . '/storage/app.sqlite');
    throw new LogicException('Missing database accepted');
} catch (App\Database\AdministrationException) {}
if (file_exists($root . '/storage/app.sqlite')) { throw new RuntimeException('Database created'); }
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $fixture, $repository, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        storageCheck(is_resource($process), 'Sessieprobe kon niet starten');
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        storageCheck(proc_close($process) === 0 && $output === '' && $errors === '', 'Verse-clone-sessie mislukt: ' . $mode . ' ' . $errors);
        storageCheck((fileperms($fixture . '/storage') & 0777) === 0700, 'Runtime-map is niet owner-only');
        foreach (glob($fixture . '/storage/sess_*') as $file) {
            storageCheck((fileperms($file) & 0777) === 0600, 'Sessiebestand is niet owner-only');
        }
        App\Runtime\Storage::directory($fixture); // Idempotent; must retain existing sessions.
        storageCheck(count(glob($fixture . '/storage/sess_*')) === 1, 'Bestaande sessie verloren');
    }
    $unsafe = $root . '/unsafe';
    mkdir($unsafe, 0700);
    file_put_contents($unsafe . '/storage', 'not a directory');
    try {
        App\Runtime\Storage::directory($unsafe);
        throw new LogicException('Bestand als storage geaccepteerd');
    } catch (RuntimeException $error) {
        storageCheck(!$error instanceof LogicException, 'Bestand als storage geaccepteerd');
    }
    unlink($unsafe . '/storage');
    symlink($root . '/toggl/storage', $unsafe . '/storage');
    try {
        App\Runtime\Storage::directory($unsafe);
        throw new LogicException('Storage-symlink geaccepteerd');
    } catch (RuntimeException $error) {
        storageCheck(!$error instanceof LogicException, 'Storage-symlink geaccepteerd');
    }
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
echo "Runtime-opslag geslaagd: verse Toggl/WeFact-sessies, 0700/0600, behoud sessies, onveilige paden geweigerd en ontbrekende database blijft geblokkeerd.\n";
