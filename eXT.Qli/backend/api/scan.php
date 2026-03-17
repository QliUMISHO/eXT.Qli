<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/NetworkScanner.php';

try {
    $detected = NetworkScanner::detectBestSubnetForServer();

    if (!$detected || empty($detected['subnet'])) {
        Response::json([
            'success' => false,
            'message' => 'Unable to detect a usable server-connected subnet.'
        ], 500);
    }

    Response::json([
        'success' => true,
        'subnet' => $detected['subnet'],
        'interface' => $detected['name'] ?? '',
        'server_ip' => $detected['ip'] ?? '',
        'prefix' => $detected['prefix'] ?? '',
        'message' => 'Detected server-connected subnet ' . $detected['subnet'] . '.'
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}