-- Never reinterpret an already rounded successful invoice as exact-time history.
CREATE TEMP TABLE exact_duration_guard (valid INTEGER CHECK (valid = 1));
INSERT INTO exact_duration_guard
SELECT CASE WHEN
    EXISTS (SELECT 1 FROM invoices WHERE actual_duration_seconds <> billable_duration_seconds)
    OR EXISTS (SELECT 1 FROM invoiced_entries WHERE invoice_id IS NOT NULL AND
               (billable_duration_seconds IS NULL OR actual_duration_seconds <> billable_duration_seconds))
THEN 0 ELSE 1 END;
DROP TABLE exact_duration_guard;

ALTER TABLE invoices RENAME COLUMN actual_duration_seconds TO duration_seconds;
ALTER TABLE invoices DROP COLUMN billable_duration_seconds;
ALTER TABLE invoiced_entries RENAME COLUMN actual_duration_seconds TO duration_seconds;
ALTER TABLE invoiced_entries DROP COLUMN billable_duration_seconds;

-- Old prepared confirmations must never write using the former time policy.
UPDATE invoice_actions
SET state = 'cancelled',
    message = 'Tijdbeleid gewijzigd: maak een nieuwe preview met exacte Toggl-seconden.'
WHERE state = 'prepared';
-- Uncertain actions and reservations are deliberately preserved for manual review.
