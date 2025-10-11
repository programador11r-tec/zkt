PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS tickets (
  ticket_no    TEXT PRIMARY KEY,
  plate        TEXT,
  receptor_nit TEXT,
  status       TEXT NOT NULL DEFAULT 'OPEN',
  entry_at     TEXT NULL,
  exit_at      TEXT NULL,
  duration_min INTEGER NULL,
  amount       NUMERIC DEFAULT 0,
  created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status);
CREATE INDEX IF NOT EXISTS idx_tickets_entry ON tickets(entry_at);

CREATE TABLE IF NOT EXISTS payments (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_no   TEXT NOT NULL,
  amount      NUMERIC NOT NULL,
  method      TEXT,
  paid_at     TEXT NOT NULL,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_no) REFERENCES tickets(ticket_no) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payments_ticket ON payments(ticket_no);
CREATE INDEX IF NOT EXISTS idx_payments_paid   ON payments(paid_at);

CREATE TABLE IF NOT EXISTS invoices (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_no     TEXT NOT NULL,
  total         NUMERIC NOT NULL,
  uuid          TEXT,
  status        TEXT NOT NULL,
  request_json  TEXT,
  response_json TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (ticket_no),
  FOREIGN KEY (ticket_no) REFERENCES tickets(ticket_no) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_invoices_uuid ON invoices(uuid);
