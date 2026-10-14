<?php use App\View\View; use App\View\Duration; use App\View\DutchDate; use App\Project\HourlyRate; use App\Toggl\TimeEntryOverview; ?>
<?php if($error): ?><p id="prepare-error" role="alert" class="error" tabindex="-1"><?= View::escape($error) ?></p><p><a href="/factuur-preview">Terug naar de preview om deze bij te werken</a> · <a href="/projecten">Projectkoppelingen controleren</a>.</p><?php endif; ?>
<?php if($model && $action): ?>
<h2><?= View::escape($model['project_name']) ?></h2>
<dl class="confirmation-summary">
<dt>Debiteur</dt><dd><?= View::escape($model['wefact_display_name'].' — '.$model['debtor_number']) ?></dd>
<dt>Periode</dt><dd><?= View::escape(DutchDate::date($model['from']).' t/m '.DutchDate::date($model['to'])) ?></dd>
<dt>Aantal geselecteerde entries</dt><dd><?= count($model['entry_ids']) ?></dd>
<dt>Werkelijke tijd</dt><dd><?= View::escape(Duration::exact($model['duration_seconds'])) ?></dd>
<dt>Factureerbare tijd</dt><dd><?= View::escape(Duration::hoursMinutes(\App\Invoice\InvoiceRepository::snapshotDuration($model))) ?></dd>
<dt>Uurtarief</dt><dd><?= View::escape(HourlyRate::format($model['hourly_rate_cents'])) ?></dd>
<dt>Factuurindeling</dt><dd><?= $model['mode']==='summary'?'Samengevat':'Per omschrijving' ?></dd>
<dt>Bedrag excl. btw</dt><dd><strong><?= View::escape(HourlyRate::format($model['total_cents'])) ?></strong></dd>
<dt>Factuurdatum</dt><dd><?= View::escape(DutchDate::date($payload['Date'])) ?></dd>
</dl>
<table><thead><tr><th>Omschrijving</th><th>Uren</th><th>Tarief excl.</th><th>Regelbedrag excl.</th></tr></thead><tbody>
<?php foreach($payload['InvoiceLines'] as $index => $line): ?><tr><td><?= View::escape($line['Description']) ?></td><td><?= View::escape(isset($model['lines'][$index]['billed_duration_seconds']) ? Duration::hoursMinutes($model['lines'][$index]['billed_duration_seconds']) : 'Onbekend (oude actie)') ?></td><td><?= View::escape(HourlyRate::format($model['hourly_rate_cents'])) ?></td><td><?= View::escape(HourlyRate::format($model['lines'][$index]['amount_cents'])) ?></td></tr><?php endforeach; ?>
</tbody></table>
<p>Tijd is weergegeven als uren:minuten. De exacte projectduur wordt eenmaal naar boven afgerond op een kwartier. WeFact ontvangt kwartieruren × het ingestelde uurtarief. Verdeelde kwartieren en regelbedragen staan hierboven.</p>
<p>Geselecteerde entry-IDs: <?= View::escape(implode(', ', $model['entry_ids'])) ?>.</p>
<p>BTW-code wordt niet meegegeven; WeFact past zijn standaard toe zoals in het officiële invoice/add-voorbeeld. Controleer de btw in het concept vóór verzending vanuit WeFact.</p>
<?php if($action['state']==='prepared'): ?>
<form method="post" action="/concept-bevestigen" data-confirm-invoice><input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>"><input type="hidden" name="token" value="<?= View::escape($token) ?>"><button name="action" value="cancel">Annuleren</button><button name="action" value="confirm" data-final-confirm>Conceptfactuur aanmaken in WeFact</button><p role="status" data-confirm-status hidden>Conceptfactuur wordt aangemaakt… Wacht op het resultaat; de Toggl-tags worden daarna bijgewerkt.</p></form>
<?php else: ?><p class="error">Deze actie is <?= View::escape($action['state']) ?>. Geen herhaling toegestaan. <a href="/facturen">Controleer de historie</a>.</p><?php endif; ?>
<?php else: ?><p>Werk eerst een <a href="/factuur-preview">factuur-preview</a> bij en kies één project ter bevestiging.</p><?php endif; ?>
