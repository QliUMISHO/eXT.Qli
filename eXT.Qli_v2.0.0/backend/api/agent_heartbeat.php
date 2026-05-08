<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/AgentRepository.php';
require_once __DIR__ . '/../lib/DatabaseHelper.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if (($input['shared_token'] ?? '') !== AGENT_SHARED_TOKEN) {
        Response::json(['success' => false, 'message' => 'Unauthorized agent token.'], 401);
    }

    if (empty($input['agent_uuid'])) {
        Response::json(['success' => false, 'message' => 'agent_uuid is required.'], 422);
    }

    $pdo = Database::connect();
    AgentRepository::upsert($pdo, $input);
    AgentRepository::insertHeartbeat($pdo, $input['agent_uuid'], $input);

    $username = AgentRepository::extractUsername($input);
    if ($username !== '' && DatabaseHelper::columnExists($pdo, 'agents', 'username')) {
        $stmt = $pdo->prepare("\n            UPDATE agents\n            SET username = :username\n            WHERE agent_uuid = :agent_uuid\n            LIMIT 1\n        ");
        $stmt->execute([
            ':username' => $username,
            ':agent_uuid' => $input['agent_uuid'],
        ]);
    }

    Response::json([
        'success' => true,
        'message' => 'Heartbeat received.',
        'username_received' => $username,
    ]);
} catch (Throwable $e) {
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}
