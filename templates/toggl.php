<?php use App\View\View; ?>
<?php if ($error): ?><p role="alert" class="error"><?= View::escape($error) ?></p><?php endif; ?>
<?php if ($status): ?>
<p class="status"><?= $status['connected'] ? 'Verbinding succesvol: account geauthenticeerd.' : 'Verbinding niet succesvol.' ?></p>
<?php if ($status['error']): ?><p role="alert" class="error"><?= View::escape($status['error']) ?></p><?php endif; ?>
<?php if ($status['user']): ?><p>Gebruiker: <?= View::escape($status['user']['name'] ?: 'Naam niet beschikbaar') ?> · <?= View::escape($status['user']['email'] ?: 'E-mail niet beschikbaar') ?></p><?php endif; ?>
<p>Laatst opgehaald: <?= View::escape($status['loaded_at']) ?></p>
<h2>Beschikbare workspaces</h2>
<?php if ($status['workspaces'] === []): ?><p>Geen workspaces beschikbaar of nog niet opgehaald.</p><?php else: ?>
<ul><?php foreach ($status['workspaces'] as $workspace): ?><li><?= View::escape($workspace['name']) ?> — ID <?= View::escape((string) $workspace['id']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($status['selected'] !== null): ?>
<h2>Projecten van workspace <?= View::escape((string) $status['selected']) ?></h2>
<?php if ($status['projects'] === null): ?><p>Projecten konden niet worden opgehaald.</p>
<?php elseif ($status['projects'] === []): ?><p>Geen projecten beschikbaar.</p>
<?php else: ?><table><thead><tr><th>Project-ID</th><th>Projectnaam</th></tr></thead><tbody>
<?php foreach ($status['projects'] as $project): ?><tr><td><?= View::escape((string) $project['id']) ?></td><td><?= View::escape($project['name']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php endif; ?>
<?php else: ?><p>Verbinding nog niet gecontroleerd. Klik op de knop om gegevens op te halen.</p><?php endif; ?>
<?php require __DIR__ . '/quota.php'; ?>
<form action="/toggl" method="post">
<input type="hidden" name="csrf" value="<?= View::escape($csrf) ?>">
<?php if (!empty($status['workspaces'])): ?>
<label for="workspace">Workspace</label><select id="workspace" name="workspace">
<?php foreach ($status['workspaces'] as $workspace): ?><option value="<?= View::escape((string) $workspace['id']) ?>" <?= $workspace['id'] === $status['selected'] ? 'selected' : '' ?>><?= View::escape($workspace['name']) ?> (<?= View::escape((string) $workspace['id']) ?>)</option><?php endforeach; ?>
</select>
<?php endif; ?>
<button type="submit">Verbinding controleren en projecten ophalen</button>
</form>
<p>Alleen deze knop haalt gegevens op. De pagina verversen gebruikt het laatste sessieresultaat. Een ophaalactie kost maximaal drie GET-requests aan Toggl.</p>
