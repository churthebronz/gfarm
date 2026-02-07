-- Seed Seasons + Active Cap system

CREATE TABLE IF NOT EXISTS vx_seasons (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  season_no INT NOT NULL,
  starts_at INT NOT NULL,
  ends_at INT NOT NULL,
  created_at INT NOT NULL,
  UNIQUE KEY vx_seasons_no_uq (season_no),
  KEY vx_seasons_window_ix (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vx_season_caps (
  season_id INT NOT NULL,
  tarif_id INT NOT NULL,
  cap INT NOT NULL DEFAULT 0,
  created_at INT NOT NULL,
  PRIMARY KEY (season_id, tarif_id),
  KEY vx_caps_tarif_ix (tarif_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add season_id to purchases (db_store)
ALTER TABLE db_store
  ADD COLUMN season_id INT NULL DEFAULT NULL;

CREATE INDEX db_store_season_ix ON db_store (season_id, tarif, status);

-- Note: when you create a new season row, seed vx_season_caps for each tarif.