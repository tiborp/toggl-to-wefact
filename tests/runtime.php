<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

function runtimeCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/runtime-audit-' . bin2hex(random_bytes(8)) . ' with spaces';
mkdir($root, 0700);
$log = $root . '/storage/logs/php-error.log';
try {
    $arguments = App\Runtime\LocalServer::arguments($root);
    runtimeCheck($arguments[0] === PHP_BINARY, 'Andere PHP-binary gebruikt');
    $serverIndex = array_search('-S', $arguments, true);
    runtimeCheck($arguments[$serverIndex + 1] === '127.0.0.1:8080', 'Server bindt niet lokaal');
    runtimeCheck(array_slice($arguments, -3) === ['-t', $root . '/public', $root . '/public/router.php'], 'Document root/router gewijzigd');
    runtimeCheck((fileperms(dirname($log)) & 0777) === 0700 && (fileperms($log) & 0777) === 0600, 'Logrechten niet afgeschermd');

    // Exercise the same PHP flags in a child process, without starting the app or any API.
    $command = array_slice($arguments, 0, $serverIndex);
    $command[] = '-r';
    $command[] = 'echo json_encode([ini_get("display_errors"),ini_get("display_startup_errors"),ini_get("log_errors"),error_reporting(),ini_get("error_log")]); trigger_error("runtime-test-warning", E_USER_WARNING);';
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    runtimeCheck(is_resource($process), 'PHP-probe kon niet starten');
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    runtimeCheck(proc_close($process) === 0, 'PHP-probe mislukt');
    $settings = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    runtimeCheck($settings === ['', '', '1', E_ALL, $log], 'Runtime-instellingen wijken af');
    runtimeCheck($errors === '' && str_contains(file_get_contents($log), 'runtime-test-warning'), 'Waarschuwing niet uitsluitend gelogd');
    App\Runtime\LocalServer::arguments($root);
    runtimeCheck(str_contains(file_get_contents($log), 'runtime-test-warning'), 'Bestaande logs overschreven');
    foreach (['Toggl', 'WeFact'] as $api) {
        runtimeCheck(!str_contains(file_get_contents(dirname(__DIR__) . '/src/' . $api . '/CurlTransport.php'), 'curl_close('), 'Overbodige curl_close teruggekeerd');
    }
    unlink($log);
    symlink($root . '/other.log', $log);
    try {
        App\Runtime\LocalServer::arguments($root);
        throw new LogicException('Logsymlink toegestaan');
    } catch (RuntimeException $error) {
        runtimeCheck(!$error instanceof LogicException, 'Logsymlink toegestaan');
    }
} finally {
    if (is_file($log) || is_link($log)) { unlink($log); }
    if (is_dir(dirname($log))) { rmdir(dirname($log)); }
    if (is_dir($root . '/storage')) { rmdir($root . '/storage'); }
    rmdir($root);
}
echo "Runtime-tests geslaagd: dezelfde binary, lokale binding, ini-overrides, log-only waarschuwingen, rechten en behoud logs.\n";
