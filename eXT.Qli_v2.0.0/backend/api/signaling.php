<?php
/**
 * MySQL-backed HTTP signaling endpoint for WebRTC.
 * Keeps the original action names and JSON contracts used by the existing dashboard and agent.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Response.php';

function extqli_signal_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function extqli_signal_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM signaling_offers WHERE created_at < (NOW() - INTERVAL 5 MINUTE)");
    $pdo->exec("DELETE FROM signaling_answers WHERE created_at < (NOW() - INTERVAL 5 MINUTE)");
    $pdo->exec("DELETE FROM signaling_ice_candidates WHERE created_at < (NOW() - INTERVAL 5 MINUTE)");
}

try {
    $pdo = Database::connect();
    extqli_signal_cleanup($pdo);

    $action = $_GET['action'] ?? '';
    $agentUuid = trim((string)($_GET['agent_uuid'] ?? ''));
    $viewerId = trim((string)($_GET['viewer_id'] ?? ''));

    if ($action === 'submit_offer') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        $input = extqli_signal_input();
        $viewerId = trim((string)($input['viewer_id'] ?? ''));
        $offerSdp = (string)($input['offer_sdp'] ?? '');

        if ($agentUuid === '' || $viewerId === '' || $offerSdp === '') {
            Response::json(['success' => false, 'message' => 'Missing agent_uuid, offer_sdp or viewer_id'], 400);
        }

        $stmt = $pdo->prepare("\n            INSERT INTO signaling_offers (agent_uuid, viewer_id, offer_sdp, created_at)\n            VALUES (:agent_uuid, :viewer_id, :offer_sdp, NOW())\n        ");
        $stmt->execute([
            ':agent_uuid' => $agentUuid,
            ':viewer_id' => $viewerId,
            ':offer_sdp' => $offerSdp,
        ]);

        Response::json(['success' => true, 'message' => 'Offer stored']);
    }

    if ($action === 'poll_offer') {
        if ($agentUuid === '') {
            Response::json(['success' => false, 'message' => 'Missing agent_uuid'], 400);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("\n            SELECT id, viewer_id, offer_sdp\n            FROM signaling_offers\n            WHERE agent_uuid = :agent_uuid\n            ORDER BY id ASC\n            LIMIT 1\n            FOR UPDATE\n        ");
        $stmt->execute([':agent_uuid' => $agentUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->commit();
            Response::json(['has_offer' => false]);
        }

        $delete = $pdo->prepare("DELETE FROM signaling_offers WHERE id = :id LIMIT 1");
        $delete->execute([':id' => $row['id']]);
        $pdo->commit();

        Response::json([
            'has_offer' => true,
            'offer_sdp' => $row['offer_sdp'],
            'viewer_id' => $row['viewer_id'],
        ]);
    }

    if ($action === 'submit_answer') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        $input = extqli_signal_input();
        $viewerId = trim((string)($input['viewer_id'] ?? ''));
        $answerSdp = (string)($input['answer_sdp'] ?? '');

        if ($viewerId === '' || $answerSdp === '') {
            Response::json(['success' => false, 'message' => 'Missing viewer_id or answer_sdp'], 400);
        }

        $stmt = $pdo->prepare("\n            INSERT INTO signaling_answers (viewer_id, answer_sdp, created_at)\n            VALUES (:viewer_id, :answer_sdp, NOW())\n        ");
        $stmt->execute([
            ':viewer_id' => $viewerId,
            ':answer_sdp' => $answerSdp,
        ]);

        Response::json(['success' => true, 'message' => 'Answer stored']);
    }

    if ($action === 'poll_answer') {
        if ($viewerId === '') {
            Response::json(['success' => false, 'message' => 'Missing viewer_id'], 400);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("\n            SELECT id, answer_sdp\n            FROM signaling_answers\n            WHERE viewer_id = :viewer_id\n            ORDER BY id ASC\n            LIMIT 1\n            FOR UPDATE\n        ");
        $stmt->execute([':viewer_id' => $viewerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->commit();
            Response::json(['has_answer' => false]);
        }

        $delete = $pdo->prepare("DELETE FROM signaling_answers WHERE id = :id LIMIT 1");
        $delete->execute([':id' => $row['id']]);
        $pdo->commit();

        Response::json([
            'has_answer' => true,
            'answer_sdp' => $row['answer_sdp'],
        ]);
    }

    if ($action === 'ice_candidate') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        $input = extqli_signal_input();
        $target = trim((string)($input['target'] ?? ($input['agent_uuid'] ?? ($input['viewer_id'] ?? ''))));
        $candidate = $input['candidate'] ?? null;

        if ($target === '' || empty($candidate)) {
            Response::json(['success' => false, 'message' => 'Missing target or candidate'], 400);
        }

        $candidateJson = is_string($candidate) ? $candidate : json_encode($candidate);
        $stmt = $pdo->prepare("\n            INSERT INTO signaling_ice_candidates (target, candidate, sdp_mid, sdp_mline_index, created_at)\n            VALUES (:target, :candidate, :sdp_mid, :sdp_mline_index, NOW())\n        ");
        $stmt->execute([
            ':target' => $target,
            ':candidate' => $candidateJson,
            ':sdp_mid' => (string)($input['sdp_mid'] ?? ''),
            ':sdp_mline_index' => (int)($input['sdp_mline_index'] ?? 0),
        ]);

        Response::json(['success' => true, 'message' => 'ICE candidate stored']);
    }

    if ($action === 'poll_ice_candidates') {
        $target = trim((string)($_GET['target'] ?? ''));
        if ($target === '') {
            Response::json(['success' => false, 'message' => 'Missing target'], 400);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("\n            SELECT id, candidate, sdp_mid, sdp_mline_index\n            FROM signaling_ice_candidates\n            WHERE target = :target\n            ORDER BY id ASC\n            FOR UPDATE\n        ");
        $stmt->execute([':target' => $target]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $pdo->commit();
            Response::json(['has_candidates' => false]);
        }

        $ids = array_map(static fn($row) => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $pdo->prepare("DELETE FROM signaling_ice_candidates WHERE id IN ($placeholders)");
        $delete->execute($ids);
        $pdo->commit();

        $candidates = array_map(static function (array $row): array {
            $decodedCandidate = json_decode($row['candidate'], true);
            return [
                'candidate' => $decodedCandidate ?? $row['candidate'],
                'sdp_mid' => $row['sdp_mid'],
                'sdp_mline_index' => (int)$row['sdp_mline_index'],
            ];
        }, $rows);

        Response::json(['has_candidates' => true, 'candidates' => $candidates]);
    }

    Response::json(['success' => false, 'message' => 'Invalid action'], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::json(['success' => false, 'message' => $e->getMessage()], 500);
}
