<?php
/**
 * HTTP signaling endpoint for WebRTC.
 * Actions:
 * - POST ?action=submit_offer&agent_uuid=XXX
 * - GET  ?action=poll_offer&agent_uuid=XXX
 * - POST ?action=submit_answer&viewer_id=XXX
 * - GET  ?action=poll_answer&viewer_id=XXX
 * - POST ?action=ice_candidate (with target, candidate, etc.)
 * - GET  ?action=poll_ice_candidates&target=XXX
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

// ---------- Submit offer (from viewer) ----------
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

// ---------- Poll offer (by agent) ----------
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

// ---------- Submit answer (from agent) ----------
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

// ---------- Poll answer (by viewer) ----------
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

// ---------- ICE candidate forwarding (optional) ----------
if ($action === 'ice_candidate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $target = $input['target'] ?? ($input['agent_uuid'] ?? $input['viewer_id'] ?? '');
    if (!$target || empty($input['candidate'])) {
        jsonResponse(['success' => false, 'message' => 'Missing target or candidate'], 400);
    }
    $candidateFile = $dataDir . '/' . $target . '.ice.json';
    $candidates = [];
    if (file_exists($candidateFile)) {
        $candidates = json_decode(file_get_contents($candidateFile), true) ?: [];
    }
    $candidates[] = [
        'candidate' => $input['candidate'],
        'sdp_mid' => $input['sdp_mid'] ?? '',
        'sdp_mline_index' => $input['sdp_mline_index'] ?? 0,
        'timestamp' => time()
    ];
    file_put_contents($candidateFile, json_encode($candidates));
    jsonResponse(['success' => true, 'message' => 'ICE candidate stored']);
}

// ---------- Poll ICE candidates (by agent or viewer) ----------
if ($action === 'poll_ice_candidates') {
    $target = $_GET['target'] ?? '';
    if (!$target) {
        jsonResponse(['success' => false, 'message' => 'Missing target'], 400);
    }
    $candidateFile = $dataDir . '/' . $target . '.ice.json';
    if (file_exists($candidateFile)) {
        $candidates = json_decode(file_get_contents($candidateFile), true) ?: [];
        unlink($candidateFile);
        jsonResponse(['has_candidates' => true, 'candidates' => $candidates]);
    } else {
        jsonResponse(['has_candidates' => false]);
    }
}

jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);