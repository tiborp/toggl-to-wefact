CREATE TABLE wefact_debtors (
    wefact_debtor_id TEXT PRIMARY KEY,
    debtor_number TEXT NOT NULL,
    display_name TEXT NOT NULL,
    company_name TEXT NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    city TEXT NOT NULL,
    email TEXT NOT NULL,
    fetched_at TEXT NOT NULL
);

CREATE TABLE wefact_cache_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    credential_fingerprint TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    debtor_count INTEGER NOT NULL CHECK (debtor_count >= 0)
);
