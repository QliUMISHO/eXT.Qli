<?php
require_once __DIR__ . '/../../config/config.php';

$input = json_decode(file_get_contents('php://input'), true);

$agent = $input['agent_uuid'] ?? '';
$task = $input['task'] ?? '';

if (!$agent || !$task) {
    echo json_encode(['success' => false, 'message' => 'Missing agent_uuid or task']);
    exit;
}

$data = $input['data'] ?? [];
$data['task'] = $task;               // ensure the task name is inside data

$payload = [
    'type' => 'task',
    'agent_uuid' => $agent,
    'data' => $data
];

if (!empty($input['task_id'])) {
    $payload['task_id'] = $input['task_id'];
}

$ch = curl_init("http://127.0.0.1:8081/send-task");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;