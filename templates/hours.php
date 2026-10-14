<?php
use App\View\View;
use App\View\Duration;
use App\View\DutchDate;
use App\Toggl\TimeEntryOverview;
use App\Project\HourlyRate;
$minimumDate ??= \App\Toggl\DateRange::earliestStart(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
?>
<?php if ($error): ?><p class="error" role="alert"><?= View::escape($error) ?></p><?php endif; ?>
<form method="post" action="/uren">
    <input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>">
    <label for="from">Van</label><input type="date" id="from" name="from" min="<?= View::escape($minimumDate) ?>" value="<?= View::escape($from) ?>" required>
    <label for="to">Tot</label><input type="date" id="to" name="to" min="<?= View::escape($minimumDate) ?>" value="<?= View::escape($to) ?>" required>
    <small>Uren ophalen kan vanaf <?= View::escape(DutchDate::date($minimumDate)) ?> (maximaal drie kalendermaanden terug).</small>
    <label><input type="checkbox" name="only_uninvoiced" value="1" <?= $onlyUninvoiced ? 'checked' : '' ?>> Alleen niet-gefactureerde uren</label>
    <label><input type="checkbox" name="show_archived" value="1" <?= !empty($hours['show_archived']) ? 'checked' : '' ?>> Ook gearchiveerde projecten</label><small>Pas deze keuze toe met Alleen filter toepassen of Uren ophalen. Projecten uit de huidige periode en het gekozen project blijven altijd beschikbaar.</small>
    <label for="project-search">Project zoeken</label><input id="project-search" type="search" data-project-search placeholder="Zoek op projectnaam"><small>De zoekfunctie filtert de keuzelijst; het geselecteerde project blijft zichtbaar. Na archiveren in Toggl vernieuw je de lokale catalogus via Projectkoppelingen.</small>
    <label for="project-filter">Project</label><select id="project-filter" name="project_filter"><option value="">Alle projecten</option>
    <?php foreach ($projectOptions ?? [] as $option): ?><option value="<?= View::escape($option['id']) ?>" <?= ($hours['project_filter'] ?? '') === $option['id'] ? 'selected' : '' ?>><?= View::escape($option['name'].(!empty($option['archived'])?' (gearchiveerd)':'')) ?></option><?php endforeach; ?></select>
    <button type="submit" name="action" value="fetch">Uren ophalen</button>
    <?php if ($hours): ?><button type="submit" name="action" value="filter">Alleen filter toepassen</button><?php endif; ?>
</form>
<p>Datums en weergave gebruiken <?= View::escape(date_default_timezone_get()) ?>. Entries worden op starttijd geselecteerd. Filteren en previewberekeningen doen geen API-requests.</p>
<?php if ($hours): ?>
<p>Resultaat voor <?= View::escape(DutchDate::date($hours['from'])) ?> t/m <?= View::escape(DutchDate::date($hours['to'])) ?> · opgehaald <?= View::escape(DutchDate::dateTime($hours['loaded_at'])) ?>.</p>
<?php if (!$hours['complete']): ?><p class="error" role="alert"><?= View::escape($hours['warning'] ?? 'Resultaten onvolledig.') ?> Preview en totalen gelden alleen voor de opgehaalde entries; dit is geen volledige facturatieselectie.</p><?php endif; ?>
<?php if ($hours['project_warning']): ?><p class="error" role="alert"><?= View::escape($hours['project_warning']) ?></p><?php endif; ?>
<?php if ($overview !== null): ?>
<?php if ($overview['groups'] === []): ?><p><?= View::escape($emptyMessage ?? \App\Toggl\ProjectChoice::emptyMessage($hours)) ?></p><?php endif; ?>
<div id="factuur-preview"></div>
<form method="post" action="/factuur-preview#factuur-preview" id="preview-selection">
<input type="hidden" name="preview_target" value="">
<input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>">
<input type="hidden" name="dataset_id" value="<?= View::escape($hours['dataset_id'] ?? '') ?>">
<?php foreach ($overview['groups'] as $group): ?>
<section class="hours-group" id="preview-project-<?= View::escape((string) ($group['project_id'] ?? 'none')) ?>">
<h2><?= View::escape($group['name']) ?></h2>
<?php if ($group['project_id'] !== null): ?><p>Project-ID <?= View::escape((string) $group['project_id']) ?></p><?php endif; ?>
<?php if (!empty($group['issue'])): ?><p class="error">Niet factureerbaar: <?= View::escape($group['issue']) ?> <a href="/projecten">Projectkoppelingen beheren</a>.</p><p>Gestopte niet-gefactureerde uren in deze groep: <?= View::escape(Duration::hoursMinutes($group['seconds'])) ?>.</p><?php endif; ?>
<?php if (array_key_exists('issue', $group) && $group['issue'] === null): ?>
<div><button type="button" data-select-project="all">Alles selecteren</button> <button type="button" data-select-project="none">Alles deselecteren</button></div>
<fieldset><legend>Factuurregels voor <?= View::escape($group['name']) ?></legend>
<label><input type="radio" data-preview-project="<?= View::escape((string) $group['project_id']) ?>" name="modes[<?= View::escape((string) $group['project_id']) ?>]" value="summary" <?= $group['mode'] === 'summary' ? 'checked' : '' ?>> Samengevat</label>
<label><input type="radio" data-preview-project="<?= View::escape((string) $group['project_id']) ?>" name="modes[<?= View::escape((string) $group['project_id']) ?>]" value="description" <?= $group['mode'] === 'description' ? 'checked' : '' ?>> Per omschrijving</label></fieldset>
<?php endif; ?>
<div class="table-scroll"><table>
<thead><tr><th>Selectie</th><th>Datum</th><th>Description</th><th>Duur</th><th>Tags</th><th>Status</th></tr></thead><tbody>
<?php foreach ($group['entries'] as $entry): $selectable = $entry['selectable'] ?? $entry['billable']; ?>
<tr>
<td><input type="checkbox" data-entry-selection <?= $selectable ? 'name="selected[' . View::escape((string) $group['project_id']) . '][]"' : 'disabled' ?> value="<?= View::escape((string) $entry['id']) ?>" aria-label="Selecteer time entry <?= View::escape((string) $entry['id']) ?>" <?= $selectable && ($entry['selected'] ?? true) ? 'checked' : '' ?>></td>
<td><?= View::escape($entry['date']) ?></td>
<td><?= View::escape($entry['description'] !== '' ? $entry['description'] : 'Geen omschrijving') ?><small>Entry-ID <?= View::escape((string) $entry['id']) ?> · workspace <?= View::escape((string) $entry['workspace_id']) ?></small></td>
<td><?= $entry['running'] ? 'Actief' : View::escape(TimeEntryOverview::duration($entry['duration'])) ?></td>
<td><?= View::escape(implode(', ', $entry['tags'])) ?></td>
<td><?php if ($entry['invoiced']): ?>Gefactureerd · <?= View::escape(implode(', ', $entry['invoice_sources'])) ?><?php endif; ?><?php if ($entry['running']): ?> Actief/running<?php elseif (!$entry['invoiced']): ?><?= !empty($entry['billing_blocked']) ? 'Onzekere factuuractie: controleer historie' : ($selectable ? 'Selecteerbaar' : 'Niet factureerbaar') ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody><tfoot><tr><th colspan="3">Geselecteerde factureerbare uren</th><td colspan="3"><?= View::escape(Duration::hoursMinutes($group['selected_seconds'] ?? $group['seconds'])) ?> (uren:minuten)</td></tr></tfoot>
</table></div>
<p>Factuurregels tonen uren:minuten. De geselecteerde projecttijd wordt eenmaal naar boven afgerond op een kwartier. Entry-durations blijven ongewijzigd.</p>
<?php if (!empty($group['preview'])): $model = $group['preview']; ?>
<section class="invoice-model">
<h3>Factuur-preview · <?= View::escape($model['project_name']) ?></h3>
<p>WeFact-debiteur: <?= View::escape($model['wefact_display_name'] . ' — ' . $model['debtor_number']) ?> · ID <?= View::escape($model['wefact_debtor_id']) ?></p>
<p>Periode: <?= View::escape(DutchDate::date($model['from'])) ?> – <?= View::escape(DutchDate::date($model['to'])) ?> · Uurtarief: <?= View::escape(HourlyRate::format($model['hourly_rate_cents'])) ?></p>
<p>Werkelijk geregistreerd: <?= View::escape(Duration::exact($model['duration_seconds'])) ?> · Factureerbare tijd: <?= View::escape(Duration::hoursMinutes($model['billed_duration_seconds'])) ?>.</p>
<table><thead><tr><th>Factuurregel</th><th>Uren</th><th>Tarief</th><th>Bedrag</th></tr></thead><tbody>
<?php foreach ($model['lines'] as $line): ?><tr><td><?= View::escape($line['description']) ?><small>Entry-IDs: <?= View::escape(implode(', ', $line['entry_ids'])) ?></small></td><td><?= View::escape(Duration::hoursMinutes($line['billed_duration_seconds'])) ?></td><td><?= View::escape(HourlyRate::format($line['hourly_rate_cents'])) ?></td><td><?= View::escape(HourlyRate::format($line['amount_cents'])) ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="3">Totaal</th><td><?= View::escape(HourlyRate::format($model['total_cents'])) ?></td></tr></tfoot></table>
<p><?= count($model['entry_ids']) ?> geselecteerde entries · Exclusief btw. Btw wordt hier nog niet berekend.</p>
<button type="submit" form="prepare-<?= View::escape((string) $model['toggl_project_id']) ?>" aria-describedby="prepare-help-<?= View::escape((string) $model['toggl_project_id']) ?>" >Conceptfactuur voorbereiden</button>
<p id="prepare-help-<?= View::escape((string) $model['toggl_project_id']) ?>" <?= !empty($group['prepare_issue']) ? 'class="error" role="status"' : '' ?>><?= View::escape($group['prepare_issue'] ?? 'Opent een aparte bevestigingspagina; er wordt hier nog geen factuur aangemaakt.') ?></p>
</section>
<?php elseif (array_key_exists('issue', $group) && $group['issue'] === null): ?><p>Geen entries geselecteerd; geen factuur-preview voor dit project.</p><?php endif; ?>
</section>
<?php endforeach; ?>
<p class="status">Totaal geselecteerde factureerbare uren: <?= View::escape(Duration::hoursMinutes($overview['seconds'])) ?>.</p>
<button type="submit">Factuur-preview bijwerken</button>
<p>Vink entries aan/uit en klik op bijwerken. Met JavaScript wordt een gewijzigde indeling automatisch bijgewerkt; zonder JavaScript gebruik je deze knop. De getoonde totalen zijn de laatste serverberekening. Iedere project-preview blijft een afzonderlijke factuur, ook bij dezelfde debiteur. Preview bijwerken maakt geen factuur. Alleen de afzonderlijke bevestiging maakt een WeFact-concept; Toggl wordt niet gewijzigd.</p>
</form>
<?php foreach ($overview['groups'] as $group): if (empty($group['preview'])) { continue; } ?>
<form id="prepare-<?= View::escape((string) $group['project_id']) ?>" method="post" action="/concept-bevestigen"><input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>"><input type="hidden" name="action" value="prepare"><input type="hidden" name="project_id" value="<?= View::escape((string) $group['project_id']) ?>"></form>
<?php endforeach; ?>
<p>Werk de preview eerst bij. Conceptfactuur voorbereiden opent een afzonderlijke bevestiging; pas de laatste bevestigingsknop doet een WeFact-write.</p>
<?php endif; ?>
<?php else: ?><p>Haal eerst uren op via het datumformulier.</p><?php endif; ?>
<?php require __DIR__ . '/quota.php'; ?>
