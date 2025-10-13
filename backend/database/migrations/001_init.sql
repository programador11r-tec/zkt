CREATE TABLE IF NOT EXISTS tickets (
  ticket_no    VARCHAR(64) PRIMARY KEY,
  plate        VARCHAR(32),
  receptor_nit VARCHAR(32),
  status       ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  entry_at     DATETIME NULL,
  exit_at      DATETIME NULL,
  duration_min INT NULL,
  amount       DECIMAL(12,2) DEFAULT 0,
  source       VARCHAR(32) DEFAULT 'external',
  raw_json     MEDIUMTEXT,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE INDEX idx_tickets_status ON tickets(status);
CREATE INDEX idx_tickets_entry ON tickets(entry_at);

CREATE TABLE IF NOT EXISTS payments (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  ticket_no   VARCHAR(64) NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  method      VARCHAR(32),
  paid_at     DATETIME NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_ticket FOREIGN KEY (ticket_no) REFERENCES tickets(ticket_no)
);

CREATE INDEX idx_payments_ticket ON payments(ticket_no);
CREATE INDEX idx_payments_paid   ON payments(paid_at);

CREATE TABLE IF NOT EXISTS invoices (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  ticket_no     VARCHAR(64) NOT NULL,
  total         DECIMAL(12,2) NOT NULL,
  uuid          VARCHAR(64),
  status        VARCHAR(16) NOT NULL,
  request_json  MEDIUMTEXT,
  response_json MEDIUMTEXT,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoice_ticket (ticket_no),
  INDEX idx_invoices_uuid (uuid),
  CONSTRAINT fk_invoices_ticket FOREIGN KEY (ticket_no) REFERENCES tickets(ticket_no)
);

CREATE TABLE IF NOT EXISTS app_settings (
  `key`       VARCHAR(64) PRIMARY KEY,
  `value`     TEXT NULL,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
