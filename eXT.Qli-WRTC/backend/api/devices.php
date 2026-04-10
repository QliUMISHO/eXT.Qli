<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

try {
    $pdo = Database::connect();
    $search = trim((string)($_GET['search'] ?? ''));

    if ($search !== '') {
        $stmt = $pdo->prepare("
            SELECT
                id,
                ip_address,
                hostname,
                mac_address,
                vendor,
                status,
                DATE_FORMAT(CONVERT_TZ(last_seen, '+00:00', '+08:00'), '%Y-%m-%d %h:%i:%s %p') AS last_seen
            FROM devices
            WHERE
                ip_address LIKE :q
                OR hostname LIKE :q
                OR mac_address LIKE :q
                OR vendor LIKE :q
            ORDER BY INET_ATON(ip_address) ASC
        ");
        $stmt->execute([':q' => '%' . $search . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT
                id,
                ip_address,
                hostname,
                mac_address,
                vendor,
                status,
                DATE_FORMAT(CONVERT_TZ(last_seen, '+00:00', '+08:00'), '%Y-%m-%d %h:%i:%s %p') AS last_seen
            FROM devices
            ORDER BY INET_ATON(ip_address) ASC
        ");
    }

    Response::json([
        'success' => true,
        'devices' => $stmt->fetchAll()
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}