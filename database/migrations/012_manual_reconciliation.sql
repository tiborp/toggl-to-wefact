CREATE TABLE invoice_reconciliation_events (
 id INTEGER PRIMARY KEY,
 token_hash TEXT NOT NULL REFERENCES invoice_actions(token_hash) ON DELETE RESTRICT,
 operation TEXT NOT NULL CHECK(operation IN ('no_invoice','link_existing')),
 reason TEXT NOT NULL,
 previous_message TEXT,
 entry_ids_json TEXT NOT NULL,
 wefact_invoice_id TEXT,
 wefact_invoice_code TEXT,
 created_at TEXT NOT NULL
);
