-- Sidecar only: existing actions, states, snapshots and reservations stay unchanged.
CREATE TABLE invoice_action_results (
    token_hash TEXT PRIMARY KEY REFERENCES invoice_actions(token_hash) ON DELETE RESTRICT,
    outcome TEXT NOT NULL CHECK (outcome IN ('rejected','unknown','success','created_review')),
    diagnostics_json TEXT NOT NULL,
    captured_at TEXT NOT NULL
);
