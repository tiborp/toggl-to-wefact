<?php
declare(strict_types=1);
namespace App\Toggl;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body, public readonly array $headers = [], public readonly array $diagnostics = []) {}
}
