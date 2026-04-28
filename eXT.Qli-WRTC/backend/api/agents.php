<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

function extqli_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n          AND COLUMN_NAME = :column_name\n    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

function extqli_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $table]);

    $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$table];
}

function extqli_clean_username($value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if (in_array($lower, [
        'unknown',
        'none',
        'null',
        '-',
        'username unavailable',
        'fetching username...',
        'waiting for username...',
        'username not reported',
    ], true)) {
        return '';
    }

    return $value;
}

function extqli_extract_username_from_array(array $payload): string
{
    $candidateKeys = [
        'endpoint_username_output',
        'username_stdout',
        'username_probe_output',
        'logged_in_username',
        'username',
        'current_user',
        'logged_in_user',
        'active_user',
        'console_user',
        'user',
    ];

    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $payload)) {
            $username = extqli_clean_username($payload[$key]);
            if ($username !== '') {
                return $username;
            }
        }
    }

    return '';
}

function extqli_extract_username_from_payload(?string $payloadJson): string
{
    if (!$payloadJson) {
        return '';
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return '';
    }

    return extqli_extract_username_from_array($payload);
}

function extqli_latest_heartbeat_username(PDO $pdo, string $agentUuid): string
{
    if ($agentUuid === '') {
        return '';
    }

    if (!extqli_table_exists($pdo, 'agent_heartbeats') || !extqli_column_exists($pdo, 'agent_heartbeats', 'payload_json')) {
        return '';
    }

    $orderColumn = '';
    foreach (['id', 'created_at', 'received_at', 'updated_at'] as $candidate) {
        if (extqli_column_exists($pdo, 'agent_heartbeats', $candidate)) {
            $orderColumn = $candidate;
            break;
        }
    }

    $orderSql = $orderColumn !== '' ? "ORDER BY {$orderColumn} DESC" : '';
    $stmt = $pdo->prepare("\n        SELECT payload_json\n        FROM agent_heartbeats\n        WHERE agent_uuid = :agent_uuid\n        {$orderSql}\n        LIMIT 100\n    ");
    $stmt->execute([':agent_uuid' => $agentUuid]);
    $payloads = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($payloads as $payloadJson) {
        $username = extqli_extract_username_from_payload((string)$payloadJson);
        if ($username !== '') {
            return $username;
        }
    }

    return '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        Response::json([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $pdo = Database::connect();

    $hasUsername = extqli_column_exists($pdo, 'agents', 'username');
    $usernameSelect = $hasUsername ? 'username,' : "NULL AS username,";

    $stmt = $pdo->query("\n        SELECT\n            agent_uuid,\n            hostname,\n            {$usernameSelect}\n            local_ip,\n            os_name,\n            architecture,\n            cpu_info,\n            ram_mb,\n            disk_free_gb,\n            wazuh_status,\n            status,\n            DATE_FORMAT(last_seen, '%Y-%m-%d %H:%i:%s') AS last_seen,\n            TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen\n        FROM agents\n        ORDER BY last_seen DESC, hostname ASC\n    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array_map(function ($row) use ($pdo, $hasUsername) {
        $seconds = isset($row['seconds_since_seen']) ? (int)$row['seconds_since_seen'] : 999999;
        $row['is_online'] = ($seconds >= 0 && $seconds <= 90);
        unset($row['seconds_since_seen']);

        $dbUsername = extqli_clean_username($row['username'] ?? '');
        $heartbeatUsername = extqli_latest_heartbeat_username($pdo, (string)($row['agent_uuid'] ?? ''));
        $username = $heartbeatUsername !== '' ? $heartbeatUsername : $dbUsername;

        $row['username'] = $username;
        $row['logged_in_username'] = $username;
        $row['display_name'] = $username;
        $row['endpoint_username_output'] = $username;
        $row['username_stdout'] = $username;

        if ($username !== '' && $hasUsername && $username !== $dbUsername) {
            $update = $pdo->prepare("\n                UPDATE agents\n                SET username = :username\n                WHERE agent_uuid = :agent_uuid\n                LIMIT 1\n            ");
            $update->execute([
                ':username' => $username,
                ':agent_uuid' => $row['agent_uuid'] ?? '',
            ]);
        }

        return $row;
    }, $rows);

    Response::json([
        'success' => true,
        'data' => $rows
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
