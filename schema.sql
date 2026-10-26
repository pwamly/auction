-- Online Auction Module schema (MariaDB / MySQL 8, InnoDB). Applied by setup.php; safe to re-run.
-- All DATETIME columns hold UTC.

CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                VARCHAR(190) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  role                 ENUM('participant','admin','auction_officer','manager') NOT NULL,
  status               ENUM('active','blocked') NOT NULL DEFAULT 'active',
  status_reason        VARCHAR(500) NULL,
  full_name            VARCHAR(150) NOT NULL,
  phone                VARCHAR(20)  NOT NULL DEFAULT '',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  totp_secret          VARCHAR(64)  NULL,
  totp_enabled_at      DATETIME     NULL,
  totp_last_step       BIGINT       NULL,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q4/Q5: individuals and companies.
CREATE TABLE IF NOT EXISTS participants (
  user_id          INT UNSIGNED NOT NULL,
  participant_type ENUM('individual','company') NOT NULL,
  nida_number      VARCHAR(20)  NULL,
  tin              VARCHAR(9)   NULL,
  company_name     VARCHAR(200) NULL,
  brela_reg_no     VARCHAR(50)  NULL,
  address          VARCHAR(300) NOT NULL,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auctions (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference           VARCHAR(20)   NULL,
  title               VARCHAR(200)  NOT NULL,
  description         TEXT          NOT NULL,
  quantity            VARCHAR(100)  NOT NULL,
  location            VARCHAR(200)  NOT NULL,
  starting_price      DECIMAL(15,2) NOT NULL,
  reserve_price       DECIMAL(15,2) NULL,
  min_increment       DECIMAL(15,2) NOT NULL,
  opens_at            DATETIME(3)   NOT NULL,
  closes_at           DATETIME(3)   NOT NULL,  -- current closing time, moves with extensions
  original_closes_at  DATETIME(3)   NOT NULL,
  terms               TEXT          NOT NULL,
  status              ENUM('draft','pending_approval','rejected','published','suspended','cancelled',
                           'pending_winner_approval','awaiting_payment','payment_overdue','completed','unsold')
                      NOT NULL DEFAULT 'draft',
  unsold_reason       ENUM('no_bids','reserve_not_met','no_eligible_winner','not_paid') NULL,
  status_reason       VARCHAR(500)  NULL,      -- latest rejection / suspension / cancellation reason
  repeat_of           INT UNSIGNED  NULL,
  created_by          INT UNSIGNED  NOT NULL,
  submitted_at        DATETIME      NULL,
  approved_by         INT UNSIGNED  NULL,
  approved_at         DATETIME      NULL,
  cancelled_by        INT UNSIGNED  NULL,
  cancelled_at        DATETIME      NULL,
  closed_at           DATETIME(3)   NULL,      -- when the closing was processed
  opening_notified_at DATETIME      NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auctions_reference (reference),
  KEY idx_auctions_status_opens (status, opens_at),
  KEY idx_auctions_status_closes (status, closes_at),
  CONSTRAINT fk_auctions_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_auctions_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
  CONSTRAINT fk_auctions_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id),
  CONSTRAINT fk_auctions_repeat_of FOREIGN KEY (repeat_of) REFERENCES auctions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auction_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id    INT UNSIGNED NOT NULL,
  kind          ENUM('photo','document') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(100) NOT NULL,
  mime          VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  uploaded_by   INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_files_auction (auction_id, kind),
  CONSTRAINT fk_files_auction FOREIGN KEY (auction_id) REFERENCES auctions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q12/Q13: unlimited bids, never edited or withdrawn.
CREATE TABLE IF NOT EXISTS bids (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id INT UNSIGNED  NOT NULL,
  user_id    INT UNSIGNED  NOT NULL,
  amount     DECIMAL(15,2) NOT NULL,
  ip         VARCHAR(45)   NOT NULL DEFAULT '',
  created_at DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_bids_auction_amount (auction_id, amount),
  KEY idx_bids_user (user_id),
  CONSTRAINT fk_bids_auction FOREIGN KEY (auction_id) REFERENCES auctions (id),
  CONSTRAINT fk_bids_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q9: terms accepted before bidding.
CREATE TABLE IF NOT EXISTS terms_acceptances (
  auction_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  accepted_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (auction_id, user_id),
  CONSTRAINT fk_terms_auction FOREIGN KEY (auction_id) REFERENCES auctions (id),
  CONSTRAINT fk_terms_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q16 (automatic) and Q28 (manual) extensions.
CREATE TABLE IF NOT EXISTS auction_extensions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id     INT UNSIGNED NOT NULL,
  kind           ENUM('auto','manual') NOT NULL,
  old_closes_at  DATETIME(3)  NOT NULL,
  new_closes_at  DATETIME(3)  NOT NULL,
  reason         VARCHAR(500) NOT NULL,
  bid_id         BIGINT UNSIGNED NULL,
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_ext_auction (auction_id),
  CONSTRAINT fk_ext_auction FOREIGN KEY (auction_id) REFERENCES auctions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q17-Q20, Q23-Q25: the highest bidder, then (if they fail) the next highest, and their payment.
CREATE TABLE IF NOT EXISTS winner_offers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  auction_id          INT UNSIGNED  NOT NULL,
  user_id             INT UNSIGNED  NOT NULL,
  bid_id              BIGINT UNSIGNED NOT NULL,
  amount              DECIMAL(15,2) NOT NULL,
  rank_no             SMALLINT UNSIGNED NOT NULL,
  status              ENUM('pending_approval','approved','rejected','defaulted','paid') NOT NULL DEFAULT 'pending_approval',
  decided_by          INT UNSIGNED  NULL,
  decided_at          DATETIME      NULL,
  decision_reason     VARCHAR(500)  NULL,
  payment_due_at      DATETIME      NULL,
  control_number      VARCHAR(30)   NULL,
  paid_at             DATETIME      NULL,
  payment_reference   VARCHAR(100)  NULL,
  payment_recorded_by INT UNSIGNED  NULL,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_offers_auction (auction_id),
  KEY idx_offers_status_due (status, payment_due_at),
  CONSTRAINT fk_offers_auction FOREIGN KEY (auction_id) REFERENCES auctions (id),
  CONSTRAINT fk_offers_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_offers_bid FOREIGN KEY (bid_id) REFERENCES bids (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q21/Q22: in-app notifications...
CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  type       VARCHAR(40)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       TEXT         NOT NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, read_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ...and email, queued so a slow mail server never delays a bid.
CREATE TABLE IF NOT EXISTS email_outbox (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email   VARCHAR(190) NOT NULL,
  to_name    VARCHAR(150) NOT NULL DEFAULT '',
  subject    VARCHAR(255) NOT NULL,
  body       TEXT         NOT NULL,
  status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_outbox_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Q31: audit trail.
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(50)  NOT NULL,
  entity_type VARCHAR(30)  NULL,
  entity_id   INT UNSIGNED NULL,
  summary     VARCHAR(500) NOT NULL,
  reason      VARCHAR(500) NULL,
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_email (email, created_at),
  KEY idx_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Access privileges the administrator grants to the Manager and Auction Officer roles.
-- The administrator role always has every permission and is not stored here.
CREATE TABLE IF NOT EXISTS role_permissions (
  role       ENUM('auction_officer','manager') NOT NULL,
  permission VARCHAR(40) NOT NULL,
  PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_state (
  name  VARCHAR(50)  NOT NULL,
  value VARCHAR(255) NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
