CREATE TABLE invoice_actions (
    token_hash TEXT PRIMARY KEY,
    action_fingerprint TEXT NOT NULL UNIQUE,
    owner_hash TEXT NOT NULL,
    dataset_id TEXT NOT NULL,
    toggl_project_id INTEGER NOT NULL,
    source_fingerprint TEXT NOT NULL,
    preview_hash TEXT NOT NULL,
    snapshot_json TEXT NOT NULL,
    payload_json TEXT,
    state TEXT NOT NULL CHECK (state IN ('prepared','cancelled','reconciliation_required','succeeded')),
    wefact_invoice_id TEXT,
    wefact_invoice_code TEXT,
    message TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE invoice_entry_reservations (
    toggl_time_entry_id INTEGER PRIMARY KEY,
    token_hash TEXT NOT NULL REFERENCES invoice_actions(token_hash) ON DELETE RESTRICT
);

CREATE TABLE invoices (
    id INTEGER PRIMARY KEY,
    token_hash TEXT NOT NULL UNIQUE REFERENCES invoice_actions(token_hash),
    toggl_project_id INTEGER NOT NULL,
    project_name TEXT NOT NULL,
    wefact_debtor_id TEXT NOT NULL,
    debtor_name TEXT NOT NULL,
    wefact_source_fingerprint TEXT NOT NULL,
    wefact_invoice_id TEXT NOT NULL,
    wefact_invoice_code TEXT NOT NULL,
    wefact_status INTEGER NOT NULL CHECK (wefact_status = 0),
    invoice_date TEXT NOT NULL,
    period_from TEXT NOT NULL,
    period_to TEXT NOT NULL,
    hourly_rate_cents INTEGER NOT NULL CHECK (hourly_rate_cents > 0),
    actual_duration_seconds INTEGER NOT NULL CHECK (actual_duration_seconds >= 0),
    billable_duration_seconds INTEGER NOT NULL CHECK (billable_duration_seconds >= 0),
    amount_excl_cents INTEGER NOT NULL CHECK (amount_excl_cents >= 0),
    line_mode TEXT NOT NULL CHECK (line_mode IN ('summary','description')),
    lines_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (wefact_source_fingerprint, wefact_invoice_id)
);
CREATE INDEX invoices_project ON invoices(toggl_project_id);

-- Retain the Phase 1 structure for legacy audit; never lose already registered IDs.
ALTER TABLE invoiced_entries RENAME TO invoiced_entries_legacy;
CREATE TABLE invoiced_entries (
    id INTEGER PRIMARY KEY,
    invoice_id INTEGER REFERENCES invoices(id) ON DELETE RESTRICT,
    toggl_time_entry_id INTEGER NOT NULL UNIQUE,
    toggl_project_id INTEGER NOT NULL,
    actual_duration_seconds INTEGER NOT NULL CHECK (actual_duration_seconds >= 0),
    billable_duration_seconds INTEGER CHECK (billable_duration_seconds IS NULL OR billable_duration_seconds >= 0),
    description TEXT NOT NULL,
    start TEXT,
    stop TEXT,
    workspace_id INTEGER,
    tags_json TEXT,
    wefact_invoice_id TEXT,
    wefact_invoice_number TEXT,
    tag_synced INTEGER NOT NULL DEFAULT 0 CHECK (tag_synced IN (0,1)),
    created_at TEXT NOT NULL
);
INSERT INTO invoiced_entries (id,toggl_time_entry_id,toggl_project_id,actual_duration_seconds,description,wefact_invoice_id,wefact_invoice_number,tag_synced,created_at)
SELECT id,toggl_time_entry_id,toggl_project_id,duration,'',wefact_invoice_id,wefact_invoice_number,tag_synced,created_at FROM invoiced_entries_legacy;
CREATE INDEX invoiced_entries_local_invoice ON invoiced_entries(invoice_id);
