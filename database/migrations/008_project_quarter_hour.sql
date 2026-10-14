-- Preserve historical exact-time invoices; apply the new policy only to future invoices.
ALTER TABLE invoices ADD COLUMN billed_duration_seconds INTEGER NOT NULL DEFAULT 0 CHECK (billed_duration_seconds >= 0);
UPDATE invoices SET billed_duration_seconds=duration_seconds;
UPDATE invoice_actions SET state='cancelled',message='Tijdbeleid gewijzigd: maak een nieuwe preview met kwartierafronding per project.' WHERE state='prepared';
