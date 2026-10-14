CREATE TABLE toggl_projects (
    toggl_project_id INTEGER PRIMARY KEY CHECK (toggl_project_id > 0),
    toggl_workspace_id INTEGER NOT NULL CHECK (toggl_workspace_id > 0),
    name TEXT NOT NULL,
    active INTEGER NOT NULL CHECK (active IN (0, 1)),
    available INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1)),
    fetched_at TEXT NOT NULL,
    credential_fingerprint TEXT NOT NULL
);
CREATE INDEX toggl_projects_workspace ON toggl_projects (toggl_workspace_id);
CREATE INDEX toggl_projects_source ON toggl_projects (credential_fingerprint);

CREATE TABLE project_mappings (
    toggl_project_id INTEGER PRIMARY KEY REFERENCES toggl_projects(toggl_project_id) ON DELETE RESTRICT,
    wefact_debtor_id TEXT,
    wefact_credential_fingerprint TEXT,
    hourly_rate_cents INTEGER CHECK (hourly_rate_cents IS NULL OR (typeof(hourly_rate_cents) = 'integer' AND hourly_rate_cents > 0)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((wefact_debtor_id IS NULL AND wefact_credential_fingerprint IS NULL) OR
           (wefact_debtor_id IS NOT NULL AND wefact_credential_fingerprint IS NOT NULL))
);
-- No FK to the replaceable WeFact cache: a missing debtor must never delete a mapping.
CREATE INDEX project_mappings_debtor ON project_mappings (wefact_debtor_id);

CREATE TABLE toggl_project_sync (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    credential_fingerprint TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    project_count INTEGER NOT NULL CHECK (project_count >= 0)
);
