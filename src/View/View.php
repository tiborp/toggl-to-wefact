<?php
declare(strict_types=1);

namespace App\View;

/** Renders shared templates and provides HTML escaping at the presentation boundary. */
final class View
{
    public function __construct(private readonly string $directory) {}

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function page(string $appName, string $title, string $message, string $path, array $data = []): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        require $this->directory . '/layout.php';
    }
}
