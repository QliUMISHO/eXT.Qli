CREATE DATABASE IF NOT EXISTS ext_qli CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ext_qli;

CREATE TABLE IF NOT EXISTS devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    hostname VARCHAR(255) DEFAULT NULL,
    mac_address VARCHAR(32) DEFAULT NULL,
    vendor VARCHAR(255) DEFAULT NULL,
    status ENUM('Online','Offline') NOT NULL DEFAULT 'Online',
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ip_address (ip_address),
    KEY idx_status (status),
    KEY idx_hostname (hostname),
    KEY idx_mac_address (mac_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;