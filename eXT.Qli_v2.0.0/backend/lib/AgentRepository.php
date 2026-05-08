<?php

require_once __DIR__ . '/DatabaseHelper.php';

class AgentRepository
{
    public static function extractUsername(array $data): string
    {
        return DatabaseHelper::extractUsernameFromArray($data);
    }

    public static function upsert(PDO $pdo, array $data): void
    {
        $hasUsernameColumn = DatabaseHelper::columnExists($pdo, 'agents', 'username');
        $username = self::extractUsername($data);

        $insertUsernameColumn = $hasUsernameColumn ? ', username' : '';
        $insertUsernameValue = $hasUsernameColumn ? ', :username' : '';
        $updateUsernameSql = $hasUsernameColumn && $username !== '' ? ', username = VALUES(username)' : '';

        $sql = "\n            INSERT INTO agents (\n                agent_uuid, hostname{$insertUsernameColumn}, os_name, os_version, architecture,\n                local_ip, mac_address, cpu_info, ram_mb, disk_total_gb,\n                disk_free_gb, uptime_seconds, wazuh_status, status,\n                approved, agent_token, last_seen\n            ) VALUES (\n                :agent_uuid, :hostname{$insertUsernameValue}, :os_name, :os_version, :architecture,\n                :local_ip, :mac_address, :cpu_info, :ram_mb, :disk_total_gb,\n                :disk_free_gb, :uptime_seconds, :wazuh_status, 'online',\n                1, :agent_token, NOW()\n            )\n            ON DUPLICATE KEY UPDATE\n                hostname = VALUES(hostname){$updateUsernameSql},\n                os_name = VALUES(os_name),\n                os_version = VALUES(os_version),\n                architecture = VALUES(architecture),\n                local_ip = VALUES(local_ip),\n                mac_address = VALUES(mac_address),\n                cpu_info = VALUES(cpu_info),\n                ram_mb = VALUES(ram_mb),\n                disk_total_gb = VALUES(disk_total_gb),\n                disk_free_gb = VALUES(disk_free_gb),\n                uptime_seconds = VALUES(uptime_seconds),\n                wazuh_status = VALUES(wazuh_status),\n                status = 'online',\n                agent_token = VALUES(agent_token),\n                last_seen = NOW()\n        ";

        $params = [
            ':agent_uuid' => $data['agent_uuid'] ?? '',
            ':hostname' => $data['hostname'] ?? '',
            ':os_name' => $data['os_name'] ?? '',
            ':os_version' => $data['os_version'] ?? '',
            ':architecture' => $data['architecture'] ?? '',
            ':local_ip' => $data['local_ip'] ?? '',
            ':mac_address' => $data['mac_address'] ?? '',
            ':cpu_info' => $data['cpu_info'] ?? '',
            ':ram_mb' => (int)($data['ram_mb'] ?? 0),
            ':disk_total_gb' => (float)($data['disk_total_gb'] ?? 0),
            ':disk_free_gb' => (float)($data['disk_free_gb'] ?? 0),
            ':uptime_seconds' => (int)($data['uptime_seconds'] ?? 0),
            ':wazuh_status' => $data['wazuh_status'] ?? 'unknown',
            ':agent_token' => $data['agent_token'] ?? '',
        ];

        if ($hasUsernameColumn) {
            $params[':username'] = $username;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($hasUsernameColumn && $username !== '') {
            $stmt = $pdo->prepare("\n                UPDATE agents\n                SET username = :username\n                WHERE agent_uuid = :agent_uuid\n                LIMIT 1\n            ");
            $stmt->execute([
                ':username' => $username,
                ':agent_uuid' => $data['agent_uuid'] ?? '',
            ]);
        }
    }

    public static function insertHeartbeat(PDO $pdo, string $agentUuid, array $payload): void
    {
        $stmt = $pdo->prepare("\n            INSERT INTO agent_heartbeats (agent_uuid, payload_json)\n            VALUES (:agent_uuid, :payload_json)\n        ");
        $stmt->execute([
            ':agent_uuid' => $agentUuid,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function getAll(PDO $pdo): array
    {
        $stmt = $pdo->query("\n            SELECT *\n            FROM agents\n            ORDER BY last_seen DESC, hostname ASC\n        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPendingTasks(PDO $pdo, string $agentUuid): array
    {
        $stmt = $pdo->prepare("\n            SELECT id, task_type, task_payload\n            FROM agent_tasks\n            WHERE agent_uuid = :agent_uuid\n              AND status = 'pending'\n            ORDER BY id ASC\n            LIMIT 10\n        ");
        $stmt->execute([':agent_uuid' => $agentUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function markTasksPicked(PDO $pdo, array $taskIds): void
    {
        if (empty($taskIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $pdo->prepare("\n            UPDATE agent_tasks\n            SET status = 'picked', picked_at = NOW()\n            WHERE id IN ($placeholders)\n        ");
        $stmt->execute($taskIds);
    }

    public static function saveTaskResult(PDO $pdo, array $data): void
    {
        $stmt = $pdo->prepare("\n            INSERT INTO agent_task_results (task_id, agent_uuid, result_status, output_text)\n            VALUES (:task_id, :agent_uuid, :result_status, :output_text)\n        ");
        $stmt->execute([
            ':task_id' => (int)$data['task_id'],
            ':agent_uuid' => $data['agent_uuid'],
            ':result_status' => $data['result_status'] ?? 'success',
            ':output_text' => $data['output_text'] ?? '',
        ]);

        $stmt = $pdo->prepare("\n            UPDATE agent_tasks\n            SET status = 'completed', completed_at = NOW()\n            WHERE id = :id\n        ");
        $stmt->execute([':id' => (int)$data['task_id']]);
    }
}
