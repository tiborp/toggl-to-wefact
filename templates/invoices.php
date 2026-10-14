<?php use App\View\View; use App\View\Duration; use App\View\DutchDate; use App\Project\HourlyRate; use App\Toggl\TimeEntryOverview; ?>
<?php $notice=$data['message'] ?? (isset($data)?null:$message); ?>
<?php if($notice): ?><p class="status" role="status"><?= View::escape($notice) ?></p><?php endif; ?>
<?php if($error): ?><p class="error" role="alert"><?= View::escape($error) ?></p><?php endif; ?>
<?php
// Presentation only: completed failures never inherit the warnings of open actions.
$openActions=array_values(array_filter($history['uncertain'] ?? [],static fn($a)=>($a['state'] ?? '')==='reconciliation_required'));
$createdReview=array_values(array_filter($history['uncertain'] ?? [],static fn($a)=>($a['outcome'] ?? '')==='created_review' && ($a['state'] ?? '')!=='reconciliation_required'));
?>
<section class="panel" aria-labelledby="concept-invoices-title">
<h2 id="concept-invoices-title">Conceptfacturen</h2>
<div class="table-scroll"><table><thead><tr><th>Datum</th><th>Project</th><th>Debiteur</th><th>Conceptnummer / ID</th><th>Periode</th><th>Factureerbare uren</th><th>Bedrag excl. btw</th><th>Toggl-tags</th></tr></thead><tbody>
<?php foreach($history['invoices'] as $invoice): ?><tr><td><?= View::escape(DutchDate::date($invoice['invoice_date'])) ?></td><td><?= View::escape($invoice['project_name']) ?><?php if (isset($invoice['id'])): ?><small><a href="/facturen/urenspecificatie?invoice_id=<?= (int)$invoice['id'] ?>">Urenspecificatie</a></small><?php endif; ?></td><td><?= View::escape($invoice['debtor_name']) ?></td><td><?= View::escape($invoice['wefact_invoice_code'].' / '.$invoice['wefact_invoice_id']) ?></td><td><?= View::escape(DutchDate::date($invoice['period_from']).' t/m '.DutchDate::date($invoice['period_to'])) ?></td><td><?= View::escape(Duration::hoursMinutes($invoice['billed_duration_seconds'] ?? $invoice['duration_seconds'])) ?><small>Werkelijk: <?= View::escape(Duration::exact($invoice['duration_seconds'])) ?></small></td><td><?= View::escape(HourlyRate::format($invoice['amount_excl_cents'])) ?></td><td>
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
<section class="panel" aria-labelledby="closed-actions-title">
<h2 id="closed-actions-title">Afgesloten factuuracties</h2>
<p>Historische pogingen waarvoor geen actie meer nodig is. Deze veranderen de status van een latere succesvolle conceptfactuur niet.</p>
<?php foreach($history['failed'] ?? [] as $action): $diagnostics=json_decode($action['diagnostics_json'] ?? '{}',true); $manual=!empty($action['manual_resolved'])||($diagnostics['manual_review']['result'] ?? null)==='no_invoice_created'; ?>
<details><summary><?= $manual ? 'Handmatig gecontroleerd en afgesloten' : 'Mislukte factuuractie' ?> · <?= View::escape(json_decode($action['snapshot_json'],true)['project_name']) ?></summary>
<p><?= View::escape($action['message']) ?></p>
<?php if($manual): ?><p>Oude actie: <?= View::escape(DutchDate::dateTime($action['created_at'])) ?> · gecontroleerd: <?= View::escape(DutchDate::dateTime($diagnostics['manual_review']['checked_at'] ?? $action['updated_at'])) ?>. De reserveringen van deze actie zijn vrijgegeven.</p><?php endif; ?>
<p>Deze bevestiging is afgesloten; er wordt niet automatisch opnieuw geprobeerd. Voor een nieuwe poging moet een nieuwe preview en bevestiging worden gemaakt.</p>
<?php require __DIR__ . '/invoice_diagnostics.php'; ?></details>
<?php endforeach; ?>
<?php foreach($history['closed_invoices'] ?? [] as $invoice): ?>
<details><summary>Handmatig verwijderd testconcept · <?= View::escape($invoice['project_name']) ?> · <?= View::escape($invoice['wefact_invoice_code'].' / '.$invoice['wefact_invoice_id']) ?></summary>
<?php if (isset($invoice['id'])): ?><p><a href="/facturen/urenspecificatie?invoice_id=<?= (int)$invoice['id'] ?>">Urenspecificatie</a></p><?php endif; ?>
<p><?= View::escape($invoice['closure_reason']) ?></p>
<p>Afgesloten: <?= View::escape(DutchDate::dateTime($invoice['closed_at'])) ?> · oorspronkelijke factuurdatum: <?= View::escape(DutchDate::date($invoice['invoice_date'])) ?>.</p>
<p>Periode: <?= View::escape(DutchDate::date($invoice['period_from']).' t/m '.DutchDate::date($invoice['period_to'])) ?> · uren: <?= View::escape(Duration::hoursMinutes($invoice['billed_duration_seconds'] ?? $invoice['duration_seconds'])) ?> · bedrag excl. btw: <?= View::escape(HourlyRate::format($invoice['amount_excl_cents'])) ?>. Werkelijk geregistreerd: <?= View::escape(Duration::exact($invoice['duration_seconds'])) ?>. Auditgegevens blijven bewaard; deze registratie blokkeert de entries niet meer.</p>
</details>
<?php endforeach; ?>
<?php if(($history['failed'] ?? [])===[] && ($history['closed_invoices'] ?? [])===[]): ?><p>Geen afgesloten factuuracties.</p><?php endif; ?>
</section>
