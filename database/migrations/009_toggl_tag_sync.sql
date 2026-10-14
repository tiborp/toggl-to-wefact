-- tag_synced=0 plus no error is pending; 0 plus error is failed; 1 is synced.
ALTER TABLE invoiced_entries ADD COLUMN tag_sync_error TEXT;
ALTER TABLE invoiced_entries ADD COLUMN tag_sync_attempted_at TEXT;
