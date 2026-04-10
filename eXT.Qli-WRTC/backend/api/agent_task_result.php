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

    if (empty($input['agent_uuid']) || empty($input['task_id'])) {
        Response::json(['success' => false, 'message' => 'agent_uuid and task_id are required.'], 422);
    }

    $pdo = Database::connect();
    AgentRepository::saveTaskResult($pdo, $input);

    Response::json([
        'success' => true,
        'message' => 'Task result saved.'
    ]);
} catch (Throwable $e) {
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}