<?php
use App\View\View;
use App\Project\HourlyRate;
use App\View\DutchDate;
?>
<?php if ($error): ?><p role="alert" class="error"><?= View::escape($error) ?></p><?php endif; ?>
<?php if ($success): ?><p role="status" class="status"><?= View::escape($success) ?></p><?php endif; ?>
<form method="post" action="<?= View::escape($projectUrl) ?>"><input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>"><button type="submit" name="action" value="sync">Projecten vernieuwen vanuit Toggl</button></form>
<?php if ($overview['sync']): ?><p>Laatste projectrefresh: <?= View::escape(DutchDate::dateTime($overview['sync']['fetched_at'])) ?> · <?= View::escape((string) $overview['sync']['project_count']) ?> projecten ontvangen.</p><?php endif; ?>
<?php if ($overview['debtor_metadata']): ?><p>Lokale WeFact-cache: <?= View::escape((string) $overview['debtor_metadata']['debtor_count']) ?> debiteuren · <?= View::escape($overview['debtor_metadata']['fetched_at']) ?>.</p><?php else: ?><p class="error">Geen WeFact-debiteurencache voor deze configuratie. Vernieuw eerst via <a href="/wefact">WeFact-status</a>.</p><?php endif; ?>
<form method="get" action="/projecten#project-list" data-project-catalog-filter><label for="project-search">Zoek projectnaam</label><input id="project-search" name="q" value="<?= View::escape($search) ?>"><label><input type="checkbox" data-project-archive-toggle name="show_archived" value="1" <?= $showArchived ? 'checked' : '' ?>> Ook gearchiveerde projecten</label><button type="submit">Toepassen</button></form>
<p>Deze lijst en de projectkeuze bij Uren gebruiken de lokale projectcatalogus. Na archiveren in Toggl: klik eenmaal op Projecten vernieuwen vanuit Toggl. Zoeken en dit archieffilter kosten geen API-requests.</p>
<?php if ($overview['projects'] === []): ?><p>Geen projecten voor deze weergave. Pas je zoekterm aan, schakel Ook gearchiveerde projecten in of vernieuw de catalogus.</p><?php endif; ?>
<div id="project-list" class="table-scroll"><table class="project-table"><thead><tr><th>Toggl-project</th><th>WeFact-debiteur</th><th>Uurtarief</th><th>Status</th><th>Acties</th></tr></thead><tbody>
<?php foreach ($overview['projects'] as $project):
    $id = (string) $project['toggl_project_id'];
    $mapping = $project['mapping'];
    $selected = $mapping['wefact_debtor_id'] ?? '';
    $rate = HourlyRate::input($mapping['hourly_rate_cents'] ?? null);
    if ($formValues !== null && $formValues['project_id'] === $id) { $selected = $formValues['debtor_id']; $rate = $formValues['rate']; }
    $currentAvailable = false;
    foreach ($overview['debtors'] as $debtor) { if ($debtor['wefact_debtor_id'] === $selected && $project['debtor'] !== null) { $currentAvailable = true; } }
?>
<tr>
<td><?= View::escape($project['name']) ?><small>Project-ID <?= View::escape($id) ?> · workspace <?= View::escape((string) $project['toggl_workspace_id']) ?></small>
<?php if (!$project['available']): ?><strong class="error">Niet meer beschikbaar in Toggl</strong><?php endif; ?><?php if (!$project['active']): ?><strong>Gearchiveerd</strong><?php endif; ?></td>
<td><select name="debtor_id" form="project-<?= View::escape($id) ?>" aria-label="Debiteur voor <?= View::escape($project['name']) ?>">
<option value="">— Niet gekoppeld —</option>
<?php if ($selected !== '' && !$currentAvailable && $mapping !== null && $selected === $mapping['wefact_debtor_id']): ?><option value="<?= View::escape($selected) ?>" selected>Debiteur ontbreekt · ID <?= View::escape($selected) ?></option><?php endif; ?>
<?php foreach ($overview['debtors'] as $debtor): ?><option value="<?= View::escape($debtor['wefact_debtor_id']) ?>" <?= $debtor['wefact_debtor_id'] === $selected && ($project['debtor'] !== null || $mapping === null || $selected !== $mapping['wefact_debtor_id']) ? 'selected' : '' ?>><?= View::escape($debtor['display_name'] . ' — ' . $debtor['debtor_number']) ?></option><?php endforeach; ?>
</select></td>
<td><input name="rate" form="project-<?= View::escape($id) ?>" inputmode="decimal" value="<?= View::escape($rate) ?>" placeholder="85,00" aria-label="Uurtarief voor <?= View::escape($project['name']) ?>"><small>Opgeslagen: <?= View::escape(HourlyRate::format($mapping['hourly_rate_cents'] ?? null)) ?></small></td>
<td><?= View::escape($project['status']) ?><?php if ($project['status'] === 'Gereed' && !$project['ready']): ?><small>Configuratie compleet; project is niet actief/beschikbaar.</small><?php endif; ?></td>
<td><form id="project-<?= View::escape($id) ?>" method="post" action="<?= View::escape($projectUrl) ?>">
<input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>"><input type="hidden" name="project_id" value="<?= View::escape($id) ?>">
<button type="submit" name="action" value="save">Opslaan</button><button type="submit" name="action" value="remove">Koppeling verwijderen</button>
</form></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<p>Een debiteur met leeg tarief mag als onvolledige configuratie worden bewaard. Alleen een actieve, beschikbare projectkoppeling met beschikbare debiteur en positief tarief is gereed voor een volgende fase. Verwijderen wist uitsluitend de lokale configuratie.</p>
<?php require __DIR__ . '/quota.php'; ?>
