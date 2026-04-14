<?php

class AgentRepository
{
    public static function upsert(PDO $pdo, array $data): void
    {
        $sql = "
            INSERT INTO agents (
                agent_uuid, hostname, os_name, os_version, architecture,
                local_ip, mac_address, cpu_info, ram_mb, disk_total_gb,
                disk_free_gb, uptime_seconds, wazuh_status, status,
                approved, agent_token, last_seen
            ) VALUES (
                :agent_uuid, :hostname, :os_name, :os_version, :architecture,
                :local_ip, :mac_address, :cpu_info, :ram_mb, :disk_total_gb,
                :disk_free_gb, :uptime_seconds, :wazuh_status, 'online',
                1, :agent_token, NOW()
            )
            ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
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
                status = 'online',
                agent_token = VALUES(agent_token),
                last_seen = NOW()
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
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
        ]);
    }

    public static function insertHeartbeat(PDO $pdo, string $agentUuid, array $payload): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO agent_heartbeats (agent_uuid, payload_json)
            VALUES (:agent_uuid, :payload_json)
        ");
        $stmt->execute([
            ':agent_uuid' => $agentUuid,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function getAll(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT *
            FROM agents
            ORDER BY last_seen DESC, hostname ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getPendingTasks(PDO $pdo, string $agentUuid): array
    {
        $stmt = $pdo->prepare("
            SELECT id, task_type, task_payload
            FROM agent_tasks
            WHERE agent_uuid = :agent_uuid
              AND status = 'pending'
            ORDER BY id ASC
            LIMIT 10
        ");
        $stmt->execute([':agent_uuid' => $agentUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function markTasksPicked(PDO $pdo, array $taskIds): void
    {
        if (empty($taskIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $pdo->prepare("
            UPDATE agent_tasks
            SET status = 'picked', picked_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($taskIds);
    }

    public static function saveTaskResult(PDO $pdo, array $data): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO agent_task_results (task_id, agent_uuid, result_status, output_text)
            VALUES (:task_id, :agent_uuid, :result_status, :output_text)
        ");
        $stmt->execute([
            ':task_id' => (int)$data['task_id'],
            ':agent_uuid' => $data['agent_uuid'],
            ':result_status' => $data['result_status'] ?? 'success',
            ':output_text' => $data['output_text'] ?? '',
        ]);

        $stmt = $pdo->prepare("
            UPDATE agent_tasks
            SET status = 'completed', completed_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => (int)$data['task_id']]);
    }
}