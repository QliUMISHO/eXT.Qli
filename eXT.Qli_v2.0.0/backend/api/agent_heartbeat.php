<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function strv(array $data, string $key, int $max = 255): string
{
    return mb_substr(trim((string)($data[$key] ?? '')), 0, $max);
}

function intv(array $data, string $key): int
{
    return (int)($data[$key] ?? 0);
}

function floatv2(array $data, string $key): float
{
    return round((float)($data[$key] ?? 0), 2);
}

function ensure_agent_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_uuid VARCHAR(128) NOT NULL,
            agent_token VARCHAR(128) NULL,
            hostname VARCHAR(190) NULL,
            username VARCHAR(190) NULL,
            os_name VARCHAR(80) NULL,
            os_version VARCHAR(255) NULL,
            architecture VARCHAR(80) NULL,
            local_ip VARCHAR(80) NULL,
            mac_address VARCHAR(80) NULL,
            cpu_info VARCHAR(255) NULL,
            ram_mb INT NULL DEFAULT 0,
            disk_total_gb DECIMAL(12,2) NULL DEFAULT 0,
            disk_free_gb DECIMAL(12,2) NULL DEFAULT 0,
            uptime_seconds BIGINT NULL DEFAULT 0,
            wazuh_status VARCHAR(80) NULL,
            screen_width INT NULL DEFAULT 0,
            screen_height INT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'online',
            approved TINYINT(1) NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_agents_uuid (agent_uuid),
            KEY idx_agents_last_seen (last_seen),
            KEY idx_agents_local_ip (local_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS extqli_agents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_uuid VARCHAR(128) NOT NULL,
            agent_token VARCHAR(128) NULL,
            hostname VARCHAR(190) NULL,
            username VARCHAR(190) NULL,
            os_name VARCHAR(80) NULL,
            os_version VARCHAR(255) NULL,
            architecture VARCHAR(80) NULL,
            local_ip VARCHAR(80) NULL,
            mac_address VARCHAR(80) NULL,
            cpu_info VARCHAR(255) NULL,
            ram_mb INT NULL DEFAULT 0,
            disk_total_gb DECIMAL(12,2) NULL DEFAULT 0,
            disk_free_gb DECIMAL(12,2) NULL DEFAULT 0,
            uptime_seconds BIGINT NULL DEFAULT 0,
            wazuh_status VARCHAR(80) NULL,
            screen_width INT NULL DEFAULT 0,
            screen_height INT NULL DEFAULT 0,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_extqli_agents_uuid (agent_uuid),
            KEY idx_extqli_agents_last_seen (last_seen),
            KEY idx_extqli_agents_local_ip (local_ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agent_heartbeats (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_uuid VARCHAR(128) NOT NULL,
            payload JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_agent_heartbeats_uuid_created (agent_uuid, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

try {
    extqli_require_post();

    $input = extqli_input();
    extqli_require_token($input);

    $agentUuid = strv($input, 'agent_uuid', 128);

    if ($agentUuid === '') {
        Response::json([
            'success' => false,
            'message' => 'agent_uuid is required.'
        ], 422);
    }

    $data = [
        'agent_uuid' => $agentUuid,
        'agent_token' => strv($input, 'agent_token', 128),
        'hostname' => strv($input, 'hostname', 190),
        'username' => strv($input, 'username', 190),
        'os_name' => strv($input, 'os_name', 80),
        'os_version' => strv($input, 'os_version', 255),
        'architecture' => strv($input, 'architecture', 80),
        'local_ip' => strv($input, 'local_ip', 80),
        'mac_address' => strv($input, 'mac_address', 80),
        'cpu_info' => strv($input, 'cpu_info', 255),
        'ram_mb' => intv($input, 'ram_mb'),
        'disk_total_gb' => floatv2($input, 'disk_total_gb'),
        'disk_free_gb' => floatv2($input, 'disk_free_gb'),
        'uptime_seconds' => intv($input, 'uptime_seconds'),
        'wazuh_status' => strv($input, 'wazuh_status', 80),
        'screen_width' => intv($input, 'screen_width'),
        'screen_height' => intv($input, 'screen_height'),
    ];

    $pdo = Database::connect();
    ensure_agent_tables($pdo);

    $upsertAgents = "
        INSERT INTO agents (
            agent_uuid, agent_token, hostname, username, os_name, os_version, architecture,
            local_ip, mac_address, cpu_info, ram_mb, disk_total_gb, disk_free_gb,
            uptime_seconds, wazuh_status, screen_width, screen_height,
            status, approved, last_seen, created_at, updated_at
        ) VALUES (
            :agent_uuid, :agent_token, :hostname, :username, :os_name, :os_version, :architecture,
            :local_ip, :mac_address, :cpu_info, :ram_mb, :disk_total_gb, :disk_free_gb,
            :uptime_seconds, :wazuh_status, :screen_width, :screen_height,
            'online', 1, NOW(), NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            agent_token = VALUES(agent_token),
            hostname = VALUES(hostname),
            username = VALUES(username),
            os_name = VALUES(os_name),
            os_version = VALUES(os_version),
            architecture = VALUES(architecture),
            local_ip = VALUES(local_ip),
            mac_address = VALUES(mac_address),
            cpu_info = VALUES(cpu_info),
            ram_mb = VALUES(ram_mb),
            disk_total_gb = VALUES(disk_total_gb),
            disk_free_gb = VALUES(disk_free_gb),
            uptime_seconds = VALUES(uptime_seconds),
            wazuh_status = VALUES(wazuh_status),
            screen_width = VALUES(screen_width),
            screen_height = VALUES(screen_height),
            status = 'online',
            approved = 1,
            last_seen = NOW(),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($upsertAgents);
    $stmt->execute($data);

    $upsertExtqli = "
        INSERT INTO extqli_agents (
            agent_uuid, agent_token, hostname, username, os_name, os_version, architecture,
            local_ip, mac_address, cpu_info, ram_mb, disk_total_gb, disk_free_gb,
            uptime_seconds, wazuh_status, screen_width, screen_height,
            last_seen, created_at, updated_at
        ) VALUES (
            :agent_uuid, :agent_token, :hostname, :username, :os_name, :os_version, :architecture,
            :local_ip, :mac_address, :cpu_info, :ram_mb, :disk_total_gb, :disk_free_gb,
            :uptime_seconds, :wazuh_status, :screen_width, :screen_height,
            NOW(), NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            agent_token = VALUES(agent_token),
            hostname = VALUES(hostname),
            username = VALUES(username),
            os_name = VALUES(os_name),
            os_version = VALUES(os_version),
            architecture = VALUES(architecture),
            local_ip = VALUES(local_ip),
            mac_address = VALUES(mac_address),
            cpu_info = VALUES(cpu_info),
            ram_mb = VALUES(ram_mb),
            disk_total_gb = VALUES(disk_total_gb),
            disk_free_gb = VALUES(disk_free_gb),
            uptime_seconds = VALUES(uptime_seconds),
            wazuh_status = VALUES(wazuh_status),
            screen_width = VALUES(screen_width),
            screen_height = VALUES(screen_height),
            last_seen = NOW(),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($upsertExtqli);
    $stmt->execute($data);

    $stmt = $pdo->prepare("
        INSERT INTO agent_heartbeats (agent_uuid, payload, created_at)
        VALUES (:agent_uuid, :payload, NOW())
    ");
    $stmt->execute([
        ':agent_uuid' => $agentUuid,
        ':payload' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    Response::json([
        'success' => true,
        'message' => 'Heartbeat received.',
        'agent_uuid' => $agentUuid,
        'server_time' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}