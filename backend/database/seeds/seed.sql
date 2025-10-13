-- TICKETS (3) — idempotente
INSERT INTO tickets (ticket_no, plate, receptor_nit, status, entry_at, exit_at, duration_min, amount, source, raw_json, created_at, updated_at)
VALUES ('T-001','P123ABC', NULL,'OPEN',
        DATE_SUB(NOW(), INTERVAL 3 HOUR), NULL, NULL, 0.00, 'seed', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  plate=VALUES(plate),
  receptor_nit=VALUES(receptor_nit),
  status=VALUES(status),
  entry_at=VALUES(entry_at),
  exit_at=VALUES(exit_at),
  duration_min=VALUES(duration_min),
  amount=VALUES(amount),
  source=VALUES(source),
  raw_json=VALUES(raw_json);

INSERT INTO tickets (ticket_no, plate, receptor_nit, status, entry_at, exit_at, duration_min, amount, source, raw_json, created_at, updated_at)
VALUES ('T-002','P987XYZ', NULL,'CLOSED',
        DATE_SUB(NOW(), INTERVAL 5 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR), 180, 25.00, 'seed', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  plate=VALUES(plate),
  receptor_nit=VALUES(receptor_nit),
  status=VALUES(status),
  entry_at=VALUES(entry_at),
  exit_at=VALUES(exit_at),
  duration_min=VALUES(duration_min),
  amount=VALUES(amount),
  source=VALUES(source),
  raw_json=VALUES(raw_json);

INSERT INTO tickets (ticket_no, plate, receptor_nit, status, entry_at, exit_at, duration_min, amount, source, raw_json, created_at, updated_at)
VALUES ('T-003','M555MOTO', NULL,'CLOSED',
        DATE_SUB(DATE_SUB(NOW(), INTERVAL 1 DAY), INTERVAL 2 HOUR),
        DATE_SUB(NOW(), INTERVAL 1 DAY), 120, 18.00, 'seed', NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  plate=VALUES(plate),
  receptor_nit=VALUES(receptor_nit),
  status=VALUES(status),
  entry_at=VALUES(entry_at),
  exit_at=VALUES(exit_at),
  duration_min=VALUES(duration_min),
  amount=VALUES(amount),
  source=VALUES(source),
  raw_json=VALUES(raw_json);

-- PAYMENTS (2)
-- Usa INSERT IGNORE si no quieres duplicar por repetir el script; o elimina duplicados después.
INSERT IGNORE INTO payments (ticket_no, amount, method, paid_at, created_at)
VALUES ('T-002', 25.00, 'cash', DATE_SUB(NOW(), INTERVAL 2 HOUR), NOW());

INSERT IGNORE INTO payments (ticket_no, amount, method, paid_at, created_at)
VALUES ('T-003', 18.00, 'card', DATE_SUB(NOW(), INTERVAL 1 DAY), NOW());
