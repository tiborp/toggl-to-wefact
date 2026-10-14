<?php
declare(strict_types=1);
namespace App\WeFact;

/** Provides invoice/show without granting invoice creation or editing capabilities. */
interface InvoiceReadTransport extends HttpTransport
{
    public function showInvoice(#[\SensitiveParameter] string $key, string $identifier): HttpResponse;
}
