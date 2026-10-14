-- Remote observations are separate from immutable creation snapshots and invoice lifecycle.
CREATE TABLE invoice_remote_status (
    invoice_id INTEGER PRIMARY KEY REFERENCES invoices(id) ON DELETE RESTRICT,
    status_code INTEGER CHECK (status_code IS NULL OR status_code >= 0),
    sub_status TEXT,
    invoice_code TEXT,
    invoice_date TEXT,
    sent_count INTEGER CHECK (sent_count IS NULL OR sent_count >= 0),
    sent_at TEXT,
    amount_incl_cents INTEGER,
    amount_paid_cents INTEGER,
    amount_outstanding_cents INTEGER,
    currency TEXT,
    paid_on TEXT,
    pay_before TEXT,
    checked_at TEXT,
    last_attempt_at TEXT NOT NULL,
    last_attempt_result TEXT NOT NULL CHECK (last_attempt_result IN ('success', 'failed')),
    last_error_kind TEXT
);
