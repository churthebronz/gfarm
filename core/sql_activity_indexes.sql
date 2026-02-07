CREATE INDEX IF NOT EXISTS idx_points_ledger_uid_ts ON db_points_ledger (uid, created_at);
CREATE INDEX IF NOT EXISTS idx_points_ledger_ts ON db_points_ledger (created_at);
CREATE INDEX IF NOT EXISTS idx_store_ts ON db_store (created_at);
