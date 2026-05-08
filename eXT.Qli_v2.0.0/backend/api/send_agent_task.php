<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Response.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    Response::json([
        'success' => false,
        'deprecated' => true,
        'message' => 'send_agent_task.php is deprecated. Agent tasks must be delivered through the active WebRTC data channel; the agent no longer listens on port 8081.',
    ], 410);
} catch (Throwable $e) {
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}
