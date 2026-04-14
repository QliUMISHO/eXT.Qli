<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        Response::json([
            'success' => false,
            'message' => 'Invalid device id.'
        ], 422);
    }

    $pdo = Database::connect();
    $stmt = $pdo->prepare("DELETE FROM devices WHERE id = :id");
    $stmt->execute([':id' => $id]);

    Response::json([
        'success' => true,
        'message' => 'Device deleted successfully.'
    ]);
} catch (Throwable $e) {
    Response::json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}