<?php
declare(strict_types=1);
namespace App\Toggl;

interface HttpTransport
{
    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse;
}
