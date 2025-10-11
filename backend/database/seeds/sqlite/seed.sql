-- Reset demo rows
DELETE FROM payments WHERE ticket_no IN ('T-002','T-003');
DELETE FROM invoices WHERE ticket_no IN ('T-002','T-003');

INSERT INTO tickets (ticket_no, plate, receptor_nit, status, entry_at, exit_at, duration_min, amount, created_at)
VALUES
  ('T-001','P123ABC',NULL,'OPEN','2024-05-01 09:15:00',NULL,NULL,0.00,CURRENT_TIMESTAMP),
  ('T-002','P987XYZ',NULL,'CLOSED','2024-05-01 06:00:00','2024-05-01 09:00:00',180,25.00,CURRENT_TIMESTAMP),
  ('T-003','M555MOTO',NULL,'CLOSED','2024-04-30 07:00:00','2024-04-30 09:00:00',120,18.00,CURRENT_TIMESTAMP)
ON CONFLICT(ticket_no) DO UPDATE SET
  plate=excluded.plate,
  receptor_nit=excluded.receptor_nit,
  status=excluded.status,
  entry_at=excluded.entry_at,
  exit_at=excluded.exit_at,
  duration_min=excluded.duration_min,
  amount=excluded.amount;

INSERT INTO payments (ticket_no, amount, method, paid_at)
VALUES
  ('T-002',25.00,'cash','2024-05-01 09:05:00'),
  ('T-003',18.00,'card','2024-04-30 09:05:00');
