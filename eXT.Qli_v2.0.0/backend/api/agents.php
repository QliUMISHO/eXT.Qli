<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/DatabaseHelper.php';

function extqli_latest_heartbeat_username(PDO $pdo, string $agentUuid): string
{
    if ($agentUuid === '') {
        return '';
    }

    if (!DatabaseHelper::tableExists($pdo, 'agent_heartbeats') || !DatabaseHelper::columnExists($pdo, 'agent_heartbeats', 'payload_json')) {
        return '';
    }

    $orderColumn = '';
    foreach (['id', 'created_at', 'received_at', 'updated_at'] as $candidate) {
        if (DatabaseHelper::columnExists($pdo, 'agent_heartbeats', $candidate)) {
            $orderColumn = $candidate;
            break;
        }
    }

    $orderSql = $orderColumn !== '' ? "ORDER BY {$orderColumn} DESC" : '';
    $stmt = $pdo->prepare("\n        SELECT payload_json\n        FROM agent_heartbeats\n        WHERE agent_uuid = :agent_uuid\n        {$orderSql}\n        LIMIT 100\n    ");
    $stmt->execute([':agent_uuid' => $agentUuid]);

    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $payloadJson) {
        $username = DatabaseHelper::extractUsernameFromPayload((string)$payloadJson);
        if ($username !== '') {
            return $username;
        }
    }

    return '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $pdo = Database::connect();
    $hasUsername = DatabaseHelper::columnExists($pdo, 'agents', 'username');
    $usernameSelect = $hasUsername ? 'username,' : 'NULL AS username,';

    $stmt = $pdo->query("\n        SELECT\n            agent_uuid,\n            hostname,\n            {$usernameSelect}\n            local_ip,\n            os_name,\n            architecture,\n            cpu_info,\n            ram_mb,\n            disk_free_gb,\n            wazuh_status,\n            status,\n            DATE_FORMAT(last_seen, '%Y-%m-%d %H:%i:%s') AS last_seen,\n            TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen\n        FROM agents\n        ORDER BY last_seen DESC, hostname ASC\n    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array_map(function (array $row) use ($pdo, $hasUsername): array {
        $seconds = isset($row['seconds_since_seen']) ? (int)$row['seconds_since_seen'] : 999999;
        $row['is_online'] = ($seconds >= 0 && $seconds <= 90);
        unset($row['seconds_since_seen']);

        $dbUsername = DatabaseHelper::cleanUsername($row['username'] ?? '');
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

    Response::json(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}
