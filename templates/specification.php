<?php use App\View\{View, Duration, DutchDate}; use App\Project\HourlyRate; ?>
<p><a href="/facturen">Terug naar facturen</a></p>
<?php if ($error): ?><p class="error" role="alert"><?= View::escape($error) ?></p><?php endif; ?>
<?php if ($specification !== null): $invoice = $specification['invoice']; ?>
<h2><?= View::escape($invoice['project_name']) ?></h2>
<p>Factuur <?= View::escape($invoice['wefact_invoice_code']) ?> · <?= View::escape($invoice['debtor_name']) ?></p>
<p>Periode: <?= View::escape(DutchDate::date($invoice['period_from']).' t/m '.DutchDate::date($invoice['period_to'])) ?> · Bedrag excl. btw: <?= View::escape(HourlyRate::format($invoice['amount_excl_cents'])) ?></p>
<?php if (!$specification['available']): ?>
<p class="status" role="status">Voor deze factuur zijn onvoldoende betrouwbare historische gegevens beschikbaar om de urenspecificatie te reconstrueren.</p>
<?php else: ?>
<h2>Urenspecificatie</h2>
<p>Werkelijk geregistreerde tijd per omschrijving. Alleen het projecttotaal is destijds voor facturatie afgerond.</p>
<div class="table-scroll"><table><thead><tr><th>Omschrijving</th><th>Geregistreerde tijd</th></tr></thead><tbody>
<?php foreach ($specification['groups'] as $group): ?>
<tr><td><details><summary><?= View::escape($group['description']) ?></summary>
<ul><?php foreach ($group['entries'] as $entry): ?>
<li><?= $entry['start'] ? View::escape(DutchDate::date($entry['start'])) : 'Datum onbekend' ?> · <?= View::escape($entry['description'] !== '' ? $entry['description'] : 'Werkzaamheden') ?> · <?= View::escape(Duration::exact($entry['duration_seconds'])) ?></li>
<?php endforeach; ?></ul></details></td><td><?= View::escape(Duration::hoursMinutes($group['duration_seconds'])) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><th>Totaal geregistreerd</th><td><?= View::escape(Duration::hoursMinutes($specification['actual_seconds'])) ?></td></tr>
<tr><th>Gefactureerd</th><td><?= View::escape(Duration::hoursMinutes($invoice['billed_duration_seconds'])) ?></td></tr></tfoot></table></div>
<p>Overzichten tonen uren:minuten; de oorspronkelijke seconden blijven behouden en zijn per registratie uitklapbaar.</p>
<?php endif; endif; ?>
