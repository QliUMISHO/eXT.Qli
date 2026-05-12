<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function ensure_extqli_agents_table(PDO $pdo): void
{
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
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ");
    $stmt->execute([':table' => $table]);

    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
          AND COLUMN_NAME = :column
    ");
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function normalize_agent_row(array $row): array
{
    foreach ($row as $key => $value) {
        if ($value === null) {
            $row[$key] = '';
        }
    }

    $username = trim((string)($row['username'] ?? ''));

    $row['logged_in_username'] = $username;
    $row['current_user'] = $username;
    $row['display_name'] = $username;
    $row['endpoint_username_output'] = $username;
    $row['username_stdout'] = $username;
    $row['username_probe_output'] = $username;

    $row['is_online'] = (bool)((int)($row['is_online'] ?? 0));
    $row['ram_mb'] = (int)($row['ram_mb'] ?? 0);
    $row['uptime_seconds'] = (int)($row['uptime_seconds'] ?? 0);
    $row['screen_width'] = (int)($row['screen_width'] ?? 0);
    $row['screen_height'] = (int)($row['screen_height'] ?? 0);
    $row['disk_total_gb'] = (float)($row['disk_total_gb'] ?? 0);
    $row['disk_free_gb'] = (float)($row['disk_free_gb'] ?? 0);

    return $row;
}

try {
    $pdo = Database::connect();
    ensure_extqli_agents_table($pdo);

    $sourceTable = 'extqli_agents';

    if (table_exists($pdo, 'agents') && !table_exists($pdo, 'extqli_agents')) {
        $sourceTable = 'agents';
    }

    if (table_exists($pdo, 'agents')) {
        $sourceTable = 'agents';
    }

    $hasStatus = column_exists($pdo, $sourceTable, 'status');
    $hasApproved = column_exists($pdo, $sourceTable, 'approved');
    $hasScreenWidth = column_exists($pdo, $sourceTable, 'screen_width');
    $hasScreenHeight = column_exists($pdo, $sourceTable, 'screen_height');
    $hasCreatedAt = column_exists($pdo, $sourceTable, 'created_at');
    $hasUpdatedAt = column_exists($pdo, $sourceTable, 'updated_at');

    $screenWidthSql = $hasScreenWidth ? "screen_width" : "0 AS screen_width";
    $screenHeightSql = $hasScreenHeight ? "screen_height" : "0 AS screen_height";
    $createdAtSql = $hasCreatedAt ? "created_at" : "last_seen AS created_at";
    $updatedAtSql = $hasUpdatedAt ? "updated_at" : "last_seen AS updated_at";

    $onlineSql = $hasStatus
        ? "CASE WHEN status = 'online' OR last_seen >= (NOW() - INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS is_online"
        : "CASE WHEN last_seen >= (NOW() - INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS is_online";

    $approvedWhere = $hasApproved ? "WHERE approved = 1" : "";

    $sql = "
        SELECT
            agent_uuid,
            agent_token,
            hostname,
            username,
            os_name,
            os_version,
            architecture,
            local_ip,
            mac_address,
            cpu_info,
            ram_mb,
            disk_total_gb,
            disk_free_gb,
            uptime_seconds,
            wazuh_status,
            {$screenWidthSql},
            {$screenHeightSql},
            last_seen,
            {$createdAtSql},
            {$updatedAtSql},
            {$onlineSql}
        FROM {$sourceTable}
        {$approvedWhere}
        ORDER BY last_seen DESC
        LIMIT 500
    ";

    $stmt = $pdo->query($sql);
    $rows = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = normalize_agent_row($row);
    }

    $online = 0;

    foreach ($rows as $row) {
        if (!empty($row['is_online'])) {
            $online++;
        }
    }

    Response::json([
        'success' => true,
        'message' => 'Agents loaded.',
        'table' => $sourceTable,
        'count' => count($rows),
        'online' => $online,
        'offline' => max(count($rows) - $online, 0),
        'server_time' => date('Y-m-d H:i:s'),
        'data' => $rows
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage(),
        'table' => 'agents/extqli_agents',
        'count' => 0,
        'online' => 0,
        'offline' => 0,
        'server_time' => date('Y-m-d H:i:s'),
        'data' => []
    ], 500);
}