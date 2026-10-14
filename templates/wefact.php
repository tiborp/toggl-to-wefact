<?php use App\View\View; ?>
<p>WEFACT_API_KEY: <?= $configured ? 'ingesteld' : 'niet ingesteld' ?>.</p>
<?php if ($error): ?><p class="error" role="alert"><?= View::escape($error) ?></p><?php endif; ?>
<?php if ($status): ?>
<p>Verbinding bij laatste poging: <?= $status['connected'] === true ? 'WeFact bereikbaar' : ($status['connected'] === false ? 'niet bereikbaar / timeout' : 'niet vastgesteld') ?>.</p>
<p>Authenticatie: <?= $status['authenticated'] === true ? 'succesvol' : ($status['authenticated'] === false ? 'mislukt' : 'niet vastgesteld') ?>.</p>
<p>Controle uitgevoerd: <?= View::escape($status['checked_at']) ?> · <?= View::escape((string) $status['requests']) ?> API-request(s).</p>
<?php if ($status['error']): ?><p class="error" role="alert"><?= View::escape($status['error']) ?></p><?php else: ?><p class="status">Refresh geslaagd: <?= View::escape((string) $status['count']) ?> debiteuren opgehaald.</p><?php endif; ?>
<?php else: ?><p>Verbinding en authenticatie nog niet gecontroleerd in deze sessie. Vernieuw expliciet om deze te testen.</p><?php endif; ?>
<form method="post" action="/wefact">
<input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>">
<input type="hidden" name="action" value="refresh">
<button type="submit">Debiteuren vernieuwen vanuit WeFact</button>
</form>
<h2>Debiteuren</h2>
<?php if ($cached['metadata']): ?>
<p>Cache: <?= View::escape((string) $cached['metadata']['debtor_count']) ?> debiteuren · laatst volledig vernieuwd <?= View::escape($cached['metadata']['fetched_at']) ?>.</p>
<form method="get" action="/wefact"><label for="search">Zoeken in lokale cache</label><input id="search" name="q" value="<?= View::escape($search) ?>"><button type="submit">Zoeken</button></form>
<?php if ($cached['debtors'] === []): ?><p>Geen debiteuren gevonden.</p><?php else: ?>
<div class="table-scroll"><table><thead><tr><th>Debiteurnr.</th><th>Naam</th><th>Plaats</th><th>E-mail</th><th>WeFact-ID</th></tr></thead><tbody>
<?php foreach ($cached['debtors'] as $debtor): ?><tr>
<td><?= View::escape($debtor['debtor_number']) ?></td><td><?= View::escape($debtor['display_name']) ?></td>
<td><?= View::escape($debtor['city'] ?: 'Niet beschikbaar') ?></td><td><?= View::escape($debtor['email']) ?></td><td><?= View::escape($debtor['wefact_debtor_id']) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php else: ?><p>Nog geen volledige cache beschikbaar voor deze API-configuratie. Klik op vernieuwen om debiteuren op te halen.</p><?php endif; ?>
<p>Openen, zoeken en verversen van deze pagina gebruiken uitsluitend SQLite. Alleen de vernieuwknop doet read-only API-opvragen. Er worden geen WeFact-gegevens gewijzigd.</p>
