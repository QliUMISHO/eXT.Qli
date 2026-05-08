CREATE TABLE IF NOT EXISTS signaling_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agent_uuid VARCHAR(128) NOT NULL,
    viewer_id VARCHAR(128) NOT NULL,
    offer_sdp LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_agent_created (agent_uuid, created_at),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signaling_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    viewer_id VARCHAR(128) NOT NULL,
    answer_sdp LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_viewer_created (viewer_id, created_at),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signaling_ice_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target VARCHAR(128) NOT NULL,
    candidate LONGTEXT NOT NULL,
    sdp_mid VARCHAR(128) NOT NULL DEFAULT '',
    sdp_mline_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_target_created (target, created_at),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
