<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    if (($input['shared_token'] ?? '') !== AGENT_SHARED_TOKEN) {
        Response::json(['success' => false, 'message' => 'Unauthorized token.'], 401);
    }

    if (empty($input['agent_uuid'])) {
        Response::json(['success' => false, 'message' => 'agent_uuid is required.'], 422);
    }

    $pdo = Database::connect();

    // Start transaction
    $pdo->beginTransaction();

    // Delete related records first (foreign keys)
    $stmt = $pdo->prepare("DELETE FROM agent_heartbeats WHERE agent_uuid = ?");
    $stmt->execute([$input['agent_uuid']]);

    $stmt = $pdo->prepare("DELETE FROM agent_task_results WHERE agent_uuid = ?");
    $stmt->execute([$input['agent_uuid']]);

    $stmt = $pdo->prepare("DELETE FROM agent_tasks WHERE agent_uuid = ?");
    $stmt->execute([$input['agent_uuid']]);

    // Finally delete the agent
    $stmt = $pdo->prepare("DELETE FROM agents WHERE agent_uuid = ?");
    $stmt->execute([$input['agent_uuid']]);

    $pdo->commit();

    Response::json(['success' => true, 'message' => 'Agent and related data deleted.']);
} catch (Throwable $e) {
    if (isset($pdo)) {
        $pdo->rollBack();
    }
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}