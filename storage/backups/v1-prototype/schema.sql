-- Lotline Auctions schema (MariaDB / MySQL, InnoDB).
-- Applied by setup.php; safe to re-run.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(32)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('bidder','admin') NOT NULL DEFAULT 'bidder',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lots (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(200)  NOT NULL,
  description    VARCHAR(2000) NOT NULL,
  starting_price DECIMAL(12,2) NOT NULL,
  min_increment  DECIMAL(12,2) NOT NULL,
  end_time       DATETIME(3)   NOT NULL,   -- stored in UTC
  created_by     INT UNSIGNED  NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at     DATETIME(3)   NULL,       -- set when an admin deletes the lot; the row is kept for reports
  PRIMARY KEY (id),
  KEY idx_lots_end_time (end_time),
  CONSTRAINT fk_lots_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bids (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_id     INT UNSIGNED  NOT NULL,
  user_id    INT UNSIGNED  NOT NULL,
  amount     DECIMAL(12,2) NOT NULL,
  created_at DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  -- UTC
  PRIMARY KEY (id),
  KEY idx_bids_lot (lot_id, id),
  KEY idx_bids_user (user_id),
  CONSTRAINT fk_bids_lot  FOREIGN KEY (lot_id)  REFERENCES lots (id) ON DELETE CASCADE,
  CONSTRAINT fk_bids_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
