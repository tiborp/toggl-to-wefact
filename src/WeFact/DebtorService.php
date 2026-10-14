<?php
declare(strict_types=1);
namespace App\WeFact;

/** Refreshes the debtor cache only after the complete API listing has been validated. */
final class DebtorService
{
    public function __construct(private readonly WeFactClient $client, private readonly DebtorCache $cache) {}

    public function refresh(string $fingerprint): array
    {
        $error = null;
        $count = null;
        try {
            $debtors = $this->client->debtors();
            $this->cache->replace($debtors, $fingerprint);
            $count = count($debtors);
        } catch (WeFactException $exception) { $error = $exception->getMessage(); }
        return $this->client->status() + ['error' => $error, 'count' => $count, 'checked_at' => date('c')];
    }
}
