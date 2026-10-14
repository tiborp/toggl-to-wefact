<?php use App\View\View; use App\View\DutchDate; ?>
<h2>API-quota</h2>
<?php if ($quotas === []): ?><p>Nog geen quota-informatie ontvangen.</p><?php endif; ?>
<?php foreach ($quotas as $scope => $quota): ?>
<p><?= View::escape($scope) ?>:
<?= $quota['remaining'] !== null ? 'Resterende requests: ' . View::escape((string) $quota['remaining']) : 'Resterende quota niet meegestuurd' ?>.
<?php if ($quota['reset_at'] !== null): ?>Reset: <?= View::escape(DutchDate::dateTime($quota['reset_at'])) ?> (nog <?= max(0, $quota['reset_at'] - time()) ?> seconden).<?php endif; ?>
<?php if ($quota['blocked_until'] > time()): ?>Requests geblokkeerd tot <?= View::escape(DutchDate::dateTime($quota['blocked_until'])) ?>.<?php endif; ?>
</p>
<?php endforeach; ?>
