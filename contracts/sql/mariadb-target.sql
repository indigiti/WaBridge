-- WaBridge target MariaDB schema (DESIGN REFERENCE, not yet an enabled driver)
-- Cloudways production recommendation. Implement and test a real adapter
-- before enabling this schema in a live application.
-- utf8mb4 + InnoDB transactions required; no foreign key to external Rust.
CREATE TABLE IF NOT EXISTS wb_api_clients (
  id VARCHAR(64) PRIMARY KEY,
  token_sha256 CHAR(64) NOT NULL UNIQUE,
  sessions_json JSON NOT NULL,
  per_minute SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NULL,
  last_used_at BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_jobs (
  id CHAR(32) PRIMARY KEY,
  client_id VARCHAR(64) NOT NULL,
  session_id VARCHAR(64) NOT NULL,
  destination VARCHAR(24) NOT NULL,
  body TEXT NOT NULL,
  idem VARCHAR(128) NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'queued',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_at BIGINT UNSIGNED NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL,
  response_code SMALLINT UNSIGNED NULL,
  error VARCHAR(128) NULL,
  engine_response TEXT NULL,
  UNIQUE KEY uq_client_idem (client_id,idem),
  KEY ix_ready (state,next_at),
  KEY ix_client_status (client_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_quotas (
  client_id VARCHAR(64) NOT NULL,
  bucket BIGINT UNSIGNED NOT NULL,
  used INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (client_id,bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_receipts (
  provider_id VARCHAR(200) PRIMARY KEY,
  job_id CHAR(32) NOT NULL,
  state VARCHAR(32) NOT NULL,
  observed_at BIGINT UNSIGNED NOT NULL,
  source_event_id VARCHAR(128) NULL,
  KEY ix_job (job_id),
  CONSTRAINT fk_receipt_job FOREIGN KEY (job_id) REFERENCES wb_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_events (
  id VARCHAR(128) PRIMARY KEY,
  session_id VARCHAR(64) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  received_at BIGINT UNSIGNED NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  KEY ix_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_event_deliveries (
  event_id VARCHAR(128) NOT NULL,
  subscriber_id VARCHAR(64) NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'queued',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_at BIGINT UNSIGNED NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL,
  last_http SMALLINT UNSIGNED NULL,
  PRIMARY KEY (event_id,subscriber_id),
  KEY ix_dispatch_ready (state,next_at),
  CONSTRAINT fk_event_delivery FOREIGN KEY (event_id) REFERENCES wb_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wb_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event VARCHAR(80) NOT NULL,
  actor VARCHAR(96) NOT NULL,
  target VARCHAR(128) NULL,
  at BIGINT UNSIGNED NOT NULL,
  details JSON NOT NULL,
  KEY ix_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
