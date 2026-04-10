<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        Response::json([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $pdo = Database::connect();

    $stmt = $pdo->query("
        SELECT
            agent_uuid,
            hostname,
            local_ip,
            os_name,
            architecture,
            cpu_info,
            ram_mb,
            disk_free_gb,
            wazuh_status,
            status,
            DATE_FORMAT(last_seen, '%Y-%m-%d %H:%i:%s') AS last_seen,
            TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen
        FROM agents
        ORDER BY last_seen DESC, hostname ASC
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array_map(function ($row) {
        $seconds = isset($row['seconds_since_seen']) ? (int)$row['seconds_since_seen'] : 999999;
        $row['is_online'] = ($seconds >= 0 && $seconds <= 90);
        unset($row['seconds_since_seen']);
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