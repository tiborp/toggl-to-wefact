<?php
declare(strict_types=1);
namespace App\WeFact;
/** Isolates draft creation so read-only clients and tests need not implement a write transport. */
interface InvoiceTransport extends HttpTransport
{
    public function addInvoice(#[\SensitiveParameter] string $key, array $payload): HttpResponse;
}
