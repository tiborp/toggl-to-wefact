<?php
declare(strict_types=1);
namespace App\Toggl;
/** Adds a single additive tag-write capability to the otherwise read-only transport contract. */
interface TagTransport extends HttpTransport
{
    public function addInvoiced(int $workspace,int $entryId, #[\SensitiveParameter] string $token): HttpResponse;
}
