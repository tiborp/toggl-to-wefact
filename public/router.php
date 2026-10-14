<?php
declare(strict_types=1);

// Alleen de expliciet toegestane lokale assets worden rechtstreeks door de lokale server geserveerd.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (in_array($path, ['/assets/style.css', '/assets/selection.js','/assets/daily.js'], true)) {
    return false;
}
require __DIR__ . '/index.php';
