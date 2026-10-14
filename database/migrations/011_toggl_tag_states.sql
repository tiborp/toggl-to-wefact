ALTER TABLE invoiced_entries ADD COLUMN tag_sync_state TEXT NOT NULL DEFAULT 'pending' CHECK (tag_sync_state IN ('pending','synced','failed','unknown'));
UPDATE invoiced_entries SET tag_sync_state=CASE WHEN tag_synced=1 THEN 'synced' WHEN tag_sync_error IS NOT NULL THEN 'failed' ELSE 'pending' END;
