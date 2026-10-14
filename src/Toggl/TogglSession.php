<?php
declare(strict_types=1);
namespace App\Toggl;

use App\Config\Config;

/** Owns session security, CSRF and invalidation of temporary data when credentials change. */
final class TogglSession
{
    public static function open(Config $config): void
    {
        ini_set('session.save_path', \App\Runtime\Storage::directory(dirname(__DIR__, 2)));
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
        header('Cache-Control: no-store');
        $fingerprint = hash('sha256', $config->togglToken());
        if (($_SESSION['toggl_fingerprint'] ?? '') !== $fingerprint) {
            unset($_SESSION['toggl_result'], $_SESSION['toggl_quota'], $_SESSION['toggl_projects'], $_SESSION['toggl_projects_complete'], $_SESSION['toggl_hours'], $_SESSION['invoice_preview']);
            $_SESSION['toggl_fingerprint'] = $fingerprint;
        }
        if (isset($_SESSION['toggl_hours']) && !isset($_SESSION['toggl_hours']['dataset_id'])) {
            $_SESSION['toggl_hours']['dataset_id'] = bin2hex(random_bytes(16));
            unset($_SESSION['invoice_preview']);
        }
        if (($_SESSION['duration_policy'] ?? '') !== 'exact-seconds-v1') {
            unset($_SESSION['invoice_preview'], $_SESSION['invoice_confirmation'], $_SESSION['invoice_tokens']);
            $_SESSION['duration_policy'] = 'exact-seconds-v1';
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function validCsrf(mixed $value): bool
    {
        return is_string($value) && hash_equals($_SESSION['csrf'], $value);
    }

    public static function rememberProjects(array $projects): void
    {
        foreach ($projects as $project) { $_SESSION['toggl_projects'][$project['id']] = $project; }
    }
}
