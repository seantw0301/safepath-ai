CREATE TABLE IF NOT EXISTS households (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  mobility ENUM('independent','walker','wheelchair') NOT NULL DEFAULT 'independent',
  ltc_level TINYINT NOT NULL DEFAULT 4,
  ltc_category TINYINT NOT NULL DEFAULT 3,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  household_id BIGINT UNSIGNED NOT NULL,
  parent_scan_id BIGINT UNSIGNED NULL,
  status ENUM('created','uploaded','preparing','rejected','segmenting','measuring','assessing','needs_reshoot','report_ready','verifying','verified','partially_verified','failed') NOT NULL DEFAULT 'created',
  video_path VARCHAR(255) NULL,
  video_frames INT NULL,
  simulated TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  summary_json JSON NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (household_id), KEY (parent_scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS frames (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  idx INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  blurred_path VARCHAR(255) NULL,
  overlay_path VARCHAR(255) NULL,
  room VARCHAR(100) NULL,
  source_frame INT NULL,
  px_per_cm DECIMAL(10,4) NULL,
  scale_source VARCHAR(20) NULL,
  UNIQUE KEY (scan_id, idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tracks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  sam_object_id INT NOT NULL,
  label VARCHAR(50) NOT NULL,
  best_frame_idx INT NULL,
  confidence DECIMAL(5,4) NULL,
  KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hazards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  track_id BIGINT UNSIGNED NULL,
  frame_idx INT NOT NULL DEFAULT 0,
  room VARCHAR(100) NULL,
  type VARCHAR(50) NOT NULL,
  name VARCHAR(100) NULL,
  action VARCHAR(20) NULL,
  bounds_json JSON NULL,
  note TEXT NULL,
  note_en TEXT NULL,
  value_cm DECIMAL(8,2) NULL,
  error_cm DECIMAL(8,2) NULL,
  level ENUM('red','yellow','green','unknown') NOT NULL,
  rule_id VARCHAR(50) NULL,
  status ENUM('open','noted','work_ordered','fixed_claimed','verified_fixed','still_open','unknown') NOT NULL DEFAULT 'open',
  evidence_json JSON NULL,
  KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS work_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  hazard_id BIGINT UNSIGNED NOT NULL,
  item VARCHAR(100) NOT NULL,
  location VARCHAR(100) NULL,
  spec_json JSON NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'construct',
  text TEXT NULL,
  text_en TEXT NULL,
  subsidy_code VARCHAR(10) NULL,
  KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subsidy_packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  pack_version VARCHAR(50) NOT NULL,
  items_json JSON NOT NULL,
  copay_json JSON NULL,
  pdf_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(50) NOT NULL,
  payload_json JSON NULL,
  status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempt INT NOT NULL DEFAULT 0,
  run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  heartbeat_at DATETIME NULL,
  error TEXT NULL,
  KEY (status, run_after), KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scan_steps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NOT NULL,
  agent VARCHAR(50) NULL,
  tool VARCHAR(50) NOT NULL,
  input_json JSON NULL,
  output_json JSON NULL,
  ms INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY (scan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS model_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scan_id BIGINT UNSIGNED NULL,
  model VARCHAR(50) NOT NULL,
  units INT NOT NULL DEFAULT 0,
  cache_hit TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hazard_overrides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hazard_id BIGINT UNSIGNED NOT NULL,
  old_level VARCHAR(10) NOT NULL,
  new_level VARCHAR(10) NOT NULL,
  reason TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
