<?php
declare(strict_types=1);
namespace App\WeFact;

interface HttpTransport
{
    // Only debtor/list and explicitly confirmed invoice/add are available; no send/edit/delete.
    public function listDebtors(#[\SensitiveParameter] string $key, int $offset, int $limit): HttpResponse;
}
