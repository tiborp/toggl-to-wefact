<?php
declare(strict_types=1);
namespace App\WeFact;

use PDO;
use Throwable;

/** Stores a complete debtor snapshot tied to a credential fingerprint, never a partial refresh. */
final class DebtorCache
{
    public function __construct(private readonly PDO $pdo) {}

    public function read(string $fingerprint, string $search = ''): array
    {
        try {
            $state = $this->pdo->prepare('SELECT fetched_at, debtor_count FROM wefact_cache_state WHERE id = 1 AND credential_fingerprint = ?');
            $state->execute([$fingerprint]);
            $metadata = $state->fetch(PDO::FETCH_ASSOC);
            if (!$metadata) { return ['metadata' => null, 'debtors' => []]; }
            $rows = $this->pdo->query('SELECT * FROM wefact_debtors')->fetchAll(PDO::FETCH_ASSOC);
            // Sorting/filtering stays local; no API lookup or wildcard SQL interpolation.
            if ($search !== '') {
                $needle = mb_strtolower($search, 'UTF-8');
                $rows = array_values(array_filter($rows, static fn ($row) => str_contains(mb_strtolower(implode(' ', [$row['display_name'], $row['debtor_number'], $row['city'], $row['email']]), 'UTF-8'), $needle)));
            }
            usort($rows, static fn ($a, $b) => strnatcmp(mb_strtolower($a['display_name'], 'UTF-8'), mb_strtolower($b['display_name'], 'UTF-8')) ?: strnatcmp($a['debtor_number'], $b['debtor_number']));
            return ['metadata' => $metadata, 'debtors' => $rows];
        } catch (Throwable) {
            throw new WeFactException('De lokale debiteurencache kon niet worden gelezen. Controleer SQLite en de bestandsrechten.', 'cache');
        }
    }

    /**
     * Replace data and freshness metadata together, only after all API pages succeeded.
     */
    public function replace(array $debtors, string $fingerprint): void
    {
        $started = false;
        try {
            $this->pdo->beginTransaction();
            $started = true;
            $fetchedAt = gmdate('c');
            // Called only after every API page has been validated. Roll back on any write failure.
            $this->pdo->exec('DELETE FROM wefact_debtors');
            $insert = $this->pdo->prepare('INSERT INTO wefact_debtors (wefact_debtor_id, debtor_number, display_name, company_name, first_name, last_name, city, email, fetched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($debtors as $row) {
                $insert->execute([$row['wefact_debtor_id'], $row['debtor_number'], $row['display_name'], $row['company_name'], $row['first_name'], $row['last_name'], $row['city'], $row['email'], $fetchedAt]);
            }
            $state = $this->pdo->prepare('INSERT INTO wefact_cache_state (id, credential_fingerprint, fetched_at, debtor_count) VALUES (1, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET credential_fingerprint = excluded.credential_fingerprint, fetched_at = excluded.fetched_at, debtor_count = excluded.debtor_count');
            $state->execute([$fingerprint, $fetchedAt, count($debtors)]);
            $this->pdo->commit();
        } catch (Throwable) {
            if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw new WeFactException('De nieuwe debiteurencache kon niet worden opgeslagen. De vorige cache blijft behouden. Controleer SQLite en de bestandsrechten.', 'cache');
        }
    }
}
