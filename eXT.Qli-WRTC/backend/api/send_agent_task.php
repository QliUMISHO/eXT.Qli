<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit;
}

$agentUuid = $input['agent_uuid'] ?? '';
$task = $input['task'] ?? '';
$taskId = $input['task_id'] ?? null;

if (!$agentUuid || !$task) {
    echo json_encode(['success' => false, 'message' => 'Missing agent_uuid or task']);
    exit;
}

// Get agent's local IP from database
try {
    $pdo = Database::connect();
    $stmt = $pdo->prepare("SELECT local_ip FROM agents WHERE agent_uuid = :uuid LIMIT 1");
    $stmt->execute([':uuid' => $agentUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['local_ip'])) {
        echo json_encode(['success' => false, 'message' => 'Agent IP not found in database']);
        exit;
    }
    $agentIp = $row['local_ip'];
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Prepare payload to forward to agent's HTTP task server
$payload = [
    'shared_token' => AGENT_SHARED_TOKEN,
    'agent_uuid' => $agentUuid,
    'task' => $task,
    'data' => $input['data'] ?? []
];
if ($taskId) {
    $payload['task_id'] = $taskId;
}

$agentUrl = "http://{$agentIp}:8081/send-task";
$ch = curl_init($agentUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['success' => false, 'message' => 'Agent unreachable: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'message' => "Agent returned HTTP $httpCode"]);
    exit;
}

// Forward the agent's response directly to the frontend
$decoded = json_decode($response, true);
if ($decoded && isset($decoded['success'])) {
    echo $response;
} else {
    // If response is not JSON, wrap it
    echo json_encode(['success' => true, 'result' => $response]);
}