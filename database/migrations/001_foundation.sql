CREATE TABLE projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    toggl_project_id INTEGER NOT NULL UNIQUE,
    toggl_project_name TEXT NOT NULL,
    wefact_debtor_id TEXT,
    hourly_rate NUMERIC CHECK (hourly_rate IS NULL OR hourly_rate >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE invoiced_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    toggl_time_entry_id INTEGER NOT NULL UNIQUE,
    toggl_project_id INTEGER NOT NULL,
    wefact_invoice_id TEXT NOT NULL,
    wefact_invoice_number TEXT,
    duration INTEGER NOT NULL CHECK (duration >= 0),
    hourly_rate NUMERIC NOT NULL CHECK (hourly_rate >= 0),
    invoiced_at TEXT NOT NULL,
    tag_synced INTEGER NOT NULL DEFAULT 0 CHECK (tag_synced IN (0, 1)),
    created_at TEXT NOT NULL
);
CREATE INDEX invoiced_entries_invoice ON invoiced_entries (wefact_invoice_id);

CREATE TABLE api_cache (
    cache_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
