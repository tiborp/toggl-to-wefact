CREATE TABLE toggl_tag_sync_attempts (
    id INTEGER PRIMARY KEY,
    invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    diagnostics_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX toggl_tag_sync_attempts_invoice ON toggl_tag_sync_attempts(invoice_id,id);
