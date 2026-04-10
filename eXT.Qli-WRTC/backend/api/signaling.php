<?php
/**
 * HTTP signaling endpoint – stores offers and answers only.
 * No ICE candidate splitting – we rely on SDP with bundled candidates.
 */

header('Content-Type: application/json');

$dataDir = __DIR__ . '/signaling_data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? '';
$agentUuid = $_GET['agent_uuid'] ?? '';
$viewerId = $_GET['viewer_id'] ?? '';

// ---------- Viewer submits offer ----------
if ($action === 'submit_offer') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$agentUuid || empty($input['offer_sdp']) || empty($input['viewer_id'])) {
        jsonResponse(['success' => false, 'message' => 'Missing agent_uuid, offer_sdp or viewer_id'], 400);
    }
    $file = $dataDir . '/' . $agentUuid . '.offer.json';
    $data = [
        'viewer_id' => $input['viewer_id'],
        'offer_sdp' => $input['offer_sdp'],
        'timestamp' => time()
    ];
    file_put_contents($file, json_encode($data));
    jsonResponse(['success' => true, 'message' => 'Offer stored']);
}

// ---------- Agent polls for offer ----------
if ($action === 'poll_offer') {
    if (!$agentUuid) {
        jsonResponse(['success' => false, 'message' => 'Missing agent_uuid'], 400);
    }
    $file = $dataDir . '/' . $agentUuid . '.offer.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        unlink($file);
        jsonResponse([
            'has_offer' => true,
            'offer_sdp' => $data['offer_sdp'],
            'viewer_id' => $data['viewer_id']
        ]);
    } else {
        jsonResponse(['has_offer' => false]);
    }
}

// ---------- Agent submits answer ----------
if ($action === 'submit_answer') {
    $input = json_decode(file_get_contents('php://input'), true);
    $viewerId = $input['viewer_id'] ?? '';
    if (!$viewerId || empty($input['answer_sdp'])) {
        jsonResponse(['success' => false, 'message' => 'Missing viewer_id or answer_sdp'], 400);
    }
    $file = $dataDir . '/' . $viewerId . '.answer.json';
    file_put_contents($file, json_encode([
        'answer_sdp' => $input['answer_sdp'],
        'timestamp' => time()
    ]));
    jsonResponse(['success' => true, 'message' => 'Answer stored']);
}

// ---------- Viewer polls for answer ----------
if ($action === 'poll_answer') {
    if (!$viewerId) {
        jsonResponse(['success' => false, 'message' => 'Missing viewer_id'], 400);
    }
    $file = $dataDir . '/' . $viewerId . '.answer.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        unlink($file);
        jsonResponse([
            'has_answer' => true,
            'answer_sdp' => $data['answer_sdp']
        ]);
    } else {
        jsonResponse(['has_answer' => false]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);