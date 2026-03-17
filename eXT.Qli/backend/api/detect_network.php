<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/NetworkScanner.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $target = trim((string)($input['subnet'] ?? ''));

    if ($target === '') {
        Response::json([
            'success' => false,
            'message' => 'Subnet or IP is required.'
        ], 422);
    }

    $scan = NetworkScanner::scan($target);

    $pdo = Database::connect();
    NetworkScanner::saveScanResults($pdo, $scan['devices'] ?? []);

    Response::json([
        'success' => true,
        'message' => 'Nmap scan completed and results were stored.',
        'input' => $target,
        'subnet' => $scan['subnet'] ?? '',
        'method' => $scan['method'] ?? 'nmap',
        'results' => $scan['devices'] ?? [],
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}