<?php use App\View\{View, DutchDate}; use App\WeFact\InvoiceStatusSnapshot; ?>
<?php if (($remote['checked_at'] ?? null) !== null): ?>
<?= View::escape(InvoiceStatusSnapshot::label($remote['status_code'])) ?>
<small><?= $remote['sent_count'] === null ? 'Verzending onbekend' : ($remote['sent_count'] > 0 ? 'Verzending geregistreerd' : 'Geen verzending geregistreerd') ?></small>
<?php if ($remote['sent_at'] !== null): ?><small>Verzenddatum: <?= View::escape(DutchDate::dateTime($remote['sent_at'])) ?></small><?php endif; ?>
<small>Gecontroleerd: <?= View::escape(DutchDate::dateTime($remote['checked_at'])) ?></small>
<?php else: ?>
Actuele WeFact-status nog niet gecontroleerd.<small>Bij aanmaak: Concept</small>
<?php endif; ?>
<?php if (InvoiceStatusSnapshot::pendingDispatch($remote, $invoice['wefact_status'] ?? null)): ?><span class="badge badge--pending">Te versturen</span><?php endif; ?>
<?php if (($remote['last_attempt_result'] ?? null) === 'failed'): ?>
<small><?= ($remote['last_error_kind'] ?? null) === 'source_mismatch' ? 'Niet gecontroleerd: API-configuratie wijkt af.' : 'Laatste statuscontrole mislukt.' ?> Eerdere gegevens blijven behouden.</small>
<?php endif; ?>
