CREATE TABLE IF NOT EXISTS extqli_headless_native_commands (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_uuid VARCHAR(128) NOT NULL,
    command_type VARCHAR(64) NOT NULL,
    command_payload JSON NULL,
    status ENUM('queued','sent','done','failed') NOT NULL DEFAULT 'queued',
    created_by VARCHAR(190) NULL,
    created_ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    done_at DATETIME NULL,
    error_message TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_hnc_agent_status (agent_uuid, status),
    KEY idx_hnc_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS extqli_headless_native_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_uuid VARCHAR(128) NULL,
    action VARCHAR(128) NOT NULL,
    details_json JSON NULL,
    username VARCHAR(190) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_hna_agent_uuid (agent_uuid),
    KEY idx_hna_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;