ALTER TABLE invoices ADD COLUMN lifecycle TEXT NOT NULL DEFAULT 'active' CHECK (lifecycle IN ('active','manual_deleted_test'));
ALTER TABLE invoices ADD COLUMN closed_at TEXT;
ALTER TABLE invoices ADD COLUMN closure_reason TEXT;

-- Keep every historical entry, while allowing a released entry to be registered again.
ALTER TABLE invoiced_entries RENAME TO invoiced_entries_before_lifecycle;
CREATE TABLE invoiced_entries (
 id INTEGER PRIMARY KEY,
 invoice_id INTEGER REFERENCES invoices(id) ON DELETE RESTRICT,
 toggl_time_entry_id INTEGER NOT NULL,
 toggl_project_id INTEGER NOT NULL,
 duration_seconds INTEGER NOT NULL CHECK (duration_seconds >= 0),
 description TEXT NOT NULL,
 start TEXT, stop TEXT, workspace_id INTEGER, tags_json TEXT,
 wefact_invoice_id TEXT, wefact_invoice_number TEXT,
 tag_synced INTEGER NOT NULL DEFAULT 0 CHECK (tag_synced IN (0,1)),
 created_at TEXT NOT NULL,
 released_at TEXT
);
INSERT INTO invoiced_entries (id,invoice_id,toggl_time_entry_id,toggl_project_id,duration_seconds,description,start,stop,workspace_id,tags_json,wefact_invoice_id,wefact_invoice_number,tag_synced,created_at)
SELECT id,invoice_id,toggl_time_entry_id,toggl_project_id,duration_seconds,description,start,stop,workspace_id,tags_json,wefact_invoice_id,wefact_invoice_number,tag_synced,created_at FROM invoiced_entries_before_lifecycle;
DROP TABLE invoiced_entries_before_lifecycle;
CREATE UNIQUE INDEX invoiced_entries_active_entry ON invoiced_entries(toggl_time_entry_id) WHERE released_at IS NULL;
CREATE UNIQUE INDEX invoiced_entries_invoice_entry ON invoiced_entries(invoice_id,toggl_time_entry_id);
CREATE INDEX invoiced_entries_local_invoice ON invoiced_entries(invoice_id);
