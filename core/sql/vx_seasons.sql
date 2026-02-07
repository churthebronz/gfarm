-- GreenFarm Seasons tables (run once if the app can't auto-create them)
-- After running this, visit /user/launch once to let the app create an active season + seed caps.

CREATE TABLE IF NOT EXISTS vx_seasons (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  season_no INT NOT NULL,
  starts_at INT NOT NULL,
  ends_at INT NOT NULL,
  created_at INT NOT NULL,
  UNIQUE KEY ux_vx_seasons_no (season_no),
  KEY ix_vx_seasons_ends (ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vx_season_caps (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  season_id INT NOT NULL,
  vault_rank INT NOT NULL,
  cap INT NOT NULL,
  used INT NOT NULL DEFAULT 0,
  updated_at INT NOT NULL,
  UNIQUE KEY ux_vx_caps (season_id, vault_rank),
  KEY ix_vx_caps_season (season_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
