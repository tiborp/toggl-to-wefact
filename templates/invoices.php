<?php use App\View\View; use App\View\Duration; use App\View\DutchDate; use App\Project\HourlyRate; ?>
<?php $notice=$data['message'] ?? (isset($data)?null:$message); ?>
<?php if($notice): ?><p class="status" role="status"><?= View::escape($notice) ?></p><?php endif; ?>
<?php if($error): ?><p class="error" role="alert"><?= View::escape($error) ?></p><?php endif; ?>
<?php
// Presentation only: completed failures never inherit the warnings of open actions.
$openActions=array_values(array_filter($history['uncertain'] ?? [],static fn($a)=>($a['state'] ?? '')==='reconciliation_required'));
$createdReview=array_values(array_filter($history['uncertain'] ?? [],static fn($a)=>($a['outcome'] ?? '')==='created_review' && ($a['state'] ?? '')!=='reconciliation_required'));
?>
<section class="panel" aria-labelledby="concept-invoices-title">
<h2 id="concept-invoices-title">Facturen</h2>
<?php if (($history['invoices'] ?? []) !== []): ?>
<form method="post" action="/facturen"><input type="hidden" name="csrf" value="<?= View::escape($csrf ?? '') ?>"><input type="hidden" name="action" value="refresh_status"><button type="submit">Status bijwerken</button></form>
<?php endif; ?>
<div class="table-scroll"><table><thead><tr><th>Datum</th><th>Project</th><th>Debiteur</th><th>Factuurnummer / ID</th><th>WeFact-status</th><th>Periode</th><th>Factureerbare uren</th><th>Bedrag excl. btw</th><th>Toggl-tags</th></tr></thead><tbody>
<?php foreach($history['invoices'] as $invoice): $remote = $history['remote_status'][$invoice['id'] ?? 0] ?? null; ?><tr><td><?= View::escape(DutchDate::date($invoice['invoice_date'])) ?></td><td><?= View::escape($invoice['project_name']) ?><?php if (isset($invoice['id'])): ?><small><a href="/facturen/urenspecificatie?invoice_id=<?= (int)$invoice['id'] ?>">Urenspecificatie</a></small><?php endif; ?></td><td><?= View::escape($invoice['debtor_name']) ?></td><td><?= View::escape(($remote['invoice_code'] ?? $invoice['wefact_invoice_code']).' / '.$invoice['wefact_invoice_id']) ?></td><td>
<?php require __DIR__.'/invoice_remote_status.php'; ?>
</td><td><?= View::escape(DutchDate::date($invoice['period_from']).' t/m '.DutchDate::date($invoice['period_to'])) ?></td><td><?= View::escape(Duration::hoursMinutes($invoice['billed_duration_seconds'] ?? $invoice['duration_seconds'])) ?><small>Werkelijk: <?= View::escape(Duration::exact($invoice['duration_seconds'])) ?></small></td><td><?= View::escape(HourlyRate::format($invoice['amount_excl_cents'])) ?></td><td>
<?php if(isset($invoice['tag_total'])): ?>
Toggl: <?= (int)$invoice['tag_synced_count'] ?>/<?= (int)$invoice['tag_total'] ?> gesynchroniseerd
<?php if($invoice['tag_synced_count']<$invoice['tag_total']): ?>
<small><?= (int)$invoice['tag_total']-(int)$invoice['tag_synced_count'] ?> nog niet gesynchroniseerd</small>
<form method="post" action="/facturen"><input type="hidden" name="csrf" value="<?= View::escape($csrf ?? '') ?>"><input type="hidden" name="action" value="sync_tags"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>"><button type="submit">Toggl-tags opnieuw synchroniseren</button></form>
<?php endif; ?>
<?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if($history['invoices']===[]): ?><p>Nog geen succesvolle lokale factuurregistraties.</p><?php endif; ?>
<?php foreach($createdReview as $action): $snapshot=json_decode($action['snapshot_json'],true); ?>
<p class="error" role="alert">WeFact-aanmaak bevestigd · lokale controle vereist · <?= View::escape($snapshot['project_name']) ?>: <?= View::escape($action['message']) ?>. De entries blijven geblokkeerd.</p>
<?php require __DIR__.'/invoice_diagnostics.php'; ?>
<?php endforeach; ?>
</section>
<?php if($openActions!==[]): ?>
<section aria-labelledby="open-actions-title">
<h2 id="open-actions-title">Handmatige controle vereist</h2>
<p class="error" role="alert">Deze openstaande acties hebben een onzekere uitkomst. Controleer ze handmatig vóór een nieuwe poging.</p>
<?php foreach($openActions as $action): $snapshot=json_decode($action['snapshot_json'],true); ?>
<section class="panel"><h3><?= ($action['outcome'] ?? null) === 'created_review' ? 'WeFact-aanmaak bevestigd · lokale controle vereist' : 'Handmatige controle vereist' ?> · <?= View::escape($snapshot['project_name']) ?></h3><p><?= View::escape(DutchDate::dateTime($action['created_at'])) ?> · <?= View::escape($action['message']??'Onzekere externe write.') ?></p>
<p>WeFact-ID: <?= View::escape($action['wefact_invoice_id']??'onbekend') ?> · conceptnummer: <?= View::escape($action['wefact_invoice_code']??'onbekend') ?></p>
<p>Verwacht bedrag excl. btw: <?= View::escape(HourlyRate::format($snapshot['total_cents'])) ?> · factureerbare tijd: <?= View::escape((\App\Invoice\InvoiceRepository::snapshotDuration($snapshot) !== null ? Duration::hoursMinutes(\App\Invoice\InvoiceRepository::snapshotDuration($snapshot)) : 'Onbekend (oude actie)')) ?>.</p>
<p>Entry-IDs: <?= View::escape(implode(', ',$snapshot['entry_ids'])) ?>. Deze entries zijn lokaal geblokkeerd voor een tweede invoice/add. Controleer WeFact en deze lokale actie handmatig; er wordt niets automatisch opnieuw aangemaakt.</p><?php require __DIR__ . '/invoice_diagnostics.php'; ?></section>
<?php endforeach; ?>
</section>
<?php endif; ?>
