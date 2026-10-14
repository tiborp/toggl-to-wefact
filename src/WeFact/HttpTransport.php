<?php
declare(strict_types=1);
namespace App\WeFact;

interface HttpTransport
{
    // Base read capability; invoice/show and confirmed creation have separate interfaces.
    public function listDebtors(#[\SensitiveParameter] string $key, int $offset, int $limit): HttpResponse;
}
