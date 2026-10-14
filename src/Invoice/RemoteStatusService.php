<?php
declare(strict_types=1);
namespace App\Invoice;

use App\WeFact\{WeFactClient, WeFactException};

/** Performs an explicit read-only refresh, keeping partial successes and never retrying requests. */
final class RemoteStatusService
{
    public function __construct(private readonly RemoteStatusRepository $repo, private readonly WeFactClient $client, private readonly string $source) {}

    /** @return array{success: int, failed: int, skipped: int, stopped: bool} */
    public function refresh(): array
    {
        $result = ['success'=>0, 'failed'=>0, 'skipped'=>0, 'stopped'=>false];
        foreach ($this->repo->candidates() as $invoice) {
            $at = gmdate('c');
            if (!hash_equals($invoice['wefact_source_fingerprint'], $this->source)) {
                $this->repo->failure((int)$invoice['id'], 'source_mismatch', $at);
                $result['skipped']++; continue;
            }
            try {
                $snapshot = $this->client->invoiceStatus($invoice['wefact_invoice_id']);
                $this->repo->success((int)$invoice['id'], $snapshot, gmdate('c'));
                $result['success']++;
            } catch (\Throwable $e) {
                // Persist only an allowlisted category, never exception messages or response bodies.
                $kind = $e instanceof WeFactException ? $e->kind : 'local';
                $safe = ['missing_key','identifier','rate_limit','authentication','access','ip','api','http','json','response','response_validation','timeout','network','pre_request'];
                if (!in_array($kind, $safe, true)) { $kind = 'local'; }
                $this->repo->failure((int)$invoice['id'], $kind, gmdate('c'));
                $result['failed']++;
                if (in_array($kind, ['missing_key','authentication','access','ip','rate_limit','local'], true)) {
                    $result['stopped'] = true; break;
                }
            }
        }
        return $result;
    }
}
