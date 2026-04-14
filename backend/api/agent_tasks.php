<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';
require_once __DIR__ . '/../lib/AgentRepository.php';

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

    Response::json([
        'success' => true,
        'message' => 'Heartbeat received.'
    ]);
} catch (Throwable $e) {
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}