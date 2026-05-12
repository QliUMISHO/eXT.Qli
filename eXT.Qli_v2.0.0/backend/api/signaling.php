<?php
declare(strict_types=1);

/**
 * eXT.Qli HTTP WebRTC Signaling API
 *
 * Replace:
 * /var/www/html/eXT.Qli_preprod/backend/api/signaling.php
 *
 * Supports:
 * - submit_offer
 * - poll_offer
 * - submit_answer
 * - poll_answer
 * - ice_candidate
 * - poll_ice_candidates
 *
 * Always returns JSON, even on server errors.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function extqli_json(array $payload, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    exit;
}

function extqli_find_root(): string
{
    return dirname(__DIR__, 2);
}

function extqli_load_config(): void
{
    $root = extqli_find_root();
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

    $paths = [
        $root . '/config/config.php',
        $root . '/config.php',
        dirname($root) . '/config.php',
        $docRoot . '/eXT.Qli_preprod/config/config.php',
        $docRoot . '/eXT.Qli_preprod/config.php',
    ];

    foreach ($paths as $path) {
        if ($path && is_file($path)) {
            require_once $path;
            return;
        }
    }

    extqli_json([
        'success' => false,
        'message' => 'Config file not found.',
        'searched' => $paths
    ], 500);
}

function extqli_pdo(): PDO
{
    extqli_load_config();

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        extqli_json([
            'success' => false,
            'message' => 'Database constants missing. Required: DB_HOST, DB_NAME, DB_USER, DB_PASS.'
        ], 500);
    }

    $port = defined('DB_PORT') ? (int)DB_PORT : 3306;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec("SET time_zone = '+08:00'");

        return $pdo;
    } catch (Throwable $e) {
        extqli_json([
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage()
        ], 500);
    }
}

function extqli_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        extqli_json([
            'success' => false,
            'message' => 'Invalid JSON request body.'
        ], 400);
    }

    return $decoded;
}

function extqli_require_method(string $method): void
{
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($actual !== strtoupper($method)) {
        extqli_json([
            'success' => false,
            'message' => 'Method not allowed. Expected ' . strtoupper($method) . '.'
        ], 405);
    }
}

function extqli_ensure_tables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS signaling_offers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agent_uuid VARCHAR(128) NOT NULL,
            viewer_id VARCHAR(190) NOT NULL,
            offer_sdp LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_signaling_offers_agent_created (agent_uuid, created_at),
            KEY idx_signaling_offers_viewer_created (viewer_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS signaling_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            viewer_id VARCHAR(190) NOT NULL,
            answer_sdp LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_signaling_answers_viewer_created (viewer_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS signaling_ice_candidates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target VARCHAR(190) NOT NULL,
            candidate LONGTEXT NOT NULL,
            sdp_mid VARCHAR(64) NULL,
            sdp_mline_index INT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_signaling_ice_target_created (target, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function extqli_column_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare("
        SELECT DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");

    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return strtolower((string)($stmt->fetchColumn() ?: ''));
}

function extqli_upgrade_tables(PDO $pdo): void
{
    try {
        if (extqli_column_type($pdo, 'signaling_offers', 'offer_sdp') !== 'longtext') {
            $pdo->exec("ALTER TABLE signaling_offers MODIFY offer_sdp LONGTEXT NOT NULL");
        }
    } catch (Throwable $e) {
    }

    try {
        if (extqli_column_type($pdo, 'signaling_answers', 'answer_sdp') !== 'longtext') {
            $pdo->exec("ALTER TABLE signaling_answers MODIFY answer_sdp LONGTEXT NOT NULL");
        }
    } catch (Throwable $e) {
    }

    try {
        if (extqli_column_type($pdo, 'signaling_ice_candidates', 'candidate') !== 'longtext') {
            $pdo->exec("ALTER TABLE signaling_ice_candidates MODIFY candidate LONGTEXT NOT NULL");
        }
    } catch (Throwable $e) {
    }
}

function extqli_cleanup(PDO $pdo): void
{
    $pdo->exec("DELETE FROM signaling_offers WHERE created_at < (NOW() - INTERVAL 10 MINUTE)");
    $pdo->exec("DELETE FROM signaling_answers WHERE created_at < (NOW() - INTERVAL 10 MINUTE)");
    $pdo->exec("DELETE FROM signaling_ice_candidates WHERE created_at < (NOW() - INTERVAL 10 MINUTE)");
}

function extqli_submit_offer(PDO $pdo): void
{
    extqli_require_method('POST');

    $agentUuid = trim((string)($_GET['agent_uuid'] ?? ''));
    $input = extqli_input();

    $viewerId = trim((string)($input['viewer_id'] ?? ''));
    $offerSdp = (string)($input['offer_sdp'] ?? '');

    if ($agentUuid === '' || $viewerId === '' || $offerSdp === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing agent_uuid, viewer_id, or offer_sdp.'
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO signaling_offers (agent_uuid, viewer_id, offer_sdp, created_at)
        VALUES (:agent_uuid, :viewer_id, :offer_sdp, NOW())
    ");

    $stmt->execute([
        ':agent_uuid' => $agentUuid,
        ':viewer_id' => $viewerId,
        ':offer_sdp' => $offerSdp,
    ]);

    extqli_json([
        'success' => true,
        'message' => 'Offer stored.',
        'agent_uuid' => $agentUuid,
        'viewer_id' => $viewerId
    ]);
}

function extqli_poll_offer(PDO $pdo): void
{
    $agentUuid = trim((string)($_GET['agent_uuid'] ?? ''));

    if ($agentUuid === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing agent_uuid.'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, viewer_id, offer_sdp
        FROM signaling_offers
        WHERE agent_uuid = :agent_uuid
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        ':agent_uuid' => $agentUuid,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->commit();

        extqli_json([
            'success' => true,
            'has_offer' => false
        ]);
    }

    $delete = $pdo->prepare("DELETE FROM signaling_offers WHERE id = :id LIMIT 1");
    $delete->execute([
        ':id' => (int)$row['id'],
    ]);

    $pdo->commit();

    extqli_json([
        'success' => true,
        'has_offer' => true,
        'offer_sdp' => (string)$row['offer_sdp'],
        'viewer_id' => (string)$row['viewer_id'],
    ]);
}

function extqli_submit_answer(PDO $pdo): void
{
    extqli_require_method('POST');

    $input = extqli_input();

    $viewerId = trim((string)($input['viewer_id'] ?? ''));
    $answerSdp = (string)($input['answer_sdp'] ?? '');

    if ($viewerId === '' || $answerSdp === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing viewer_id or answer_sdp.'
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO signaling_answers (viewer_id, answer_sdp, created_at)
        VALUES (:viewer_id, :answer_sdp, NOW())
    ");

    $stmt->execute([
        ':viewer_id' => $viewerId,
        ':answer_sdp' => $answerSdp,
    ]);

    extqli_json([
        'success' => true,
        'message' => 'Answer stored.',
        'viewer_id' => $viewerId
    ]);
}

function extqli_poll_answer(PDO $pdo): void
{
    $viewerId = trim((string)($_GET['viewer_id'] ?? ''));

    if ($viewerId === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing viewer_id.'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, answer_sdp
        FROM signaling_answers
        WHERE viewer_id = :viewer_id
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        ':viewer_id' => $viewerId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->commit();

        extqli_json([
            'success' => true,
            'has_answer' => false
        ]);
    }

    $delete = $pdo->prepare("DELETE FROM signaling_answers WHERE id = :id LIMIT 1");
    $delete->execute([
        ':id' => (int)$row['id'],
    ]);

    $pdo->commit();

    extqli_json([
        'success' => true,
        'has_answer' => true,
        'answer_sdp' => (string)$row['answer_sdp'],
    ]);
}

function extqli_submit_ice_candidate(PDO $pdo): void
{
    extqli_require_method('POST');

    $input = extqli_input();

    $target = trim((string)($input['target'] ?? ($input['agent_uuid'] ?? ($input['viewer_id'] ?? ''))));
    $candidate = $input['candidate'] ?? null;

    if ($target === '' || $candidate === null || $candidate === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing target or candidate.'
        ], 400);
    }

    $candidateJson = is_string($candidate)
        ? $candidate
        : json_encode($candidate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO signaling_ice_candidates (
            target,
            candidate,
            sdp_mid,
            sdp_mline_index,
            created_at
        ) VALUES (
            :target,
            :candidate,
            :sdp_mid,
            :sdp_mline_index,
            NOW()
        )
    ");

    $stmt->execute([
        ':target' => $target,
        ':candidate' => $candidateJson,
        ':sdp_mid' => (string)($input['sdp_mid'] ?? ''),
        ':sdp_mline_index' => (int)($input['sdp_mline_index'] ?? 0),
    ]);

    extqli_json([
        'success' => true,
        'message' => 'ICE candidate stored.',
        'target' => $target
    ]);
}

function extqli_poll_ice_candidates(PDO $pdo): void
{
    $target = trim((string)($_GET['target'] ?? ''));

    if ($target === '') {
        extqli_json([
            'success' => false,
            'message' => 'Missing target.'
        ], 400);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT id, candidate, sdp_mid, sdp_mline_index
        FROM signaling_ice_candidates
        WHERE target = :target
        ORDER BY id ASC
        FOR UPDATE
    ");

    $stmt->execute([
        ':target' => $target,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->commit();

        extqli_json([
            'success' => true,
            'has_candidates' => false,
            'candidates' => []
        ]);
    }

    $ids = [];

    foreach ($rows as $row) {
        $ids[] = (int)$row['id'];
    }

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $pdo->prepare("DELETE FROM signaling_ice_candidates WHERE id IN ({$placeholders})");
        $delete->execute($ids);
    }

    $pdo->commit();

    $candidates = [];

    foreach ($rows as $row) {
        $decoded = json_decode((string)$row['candidate'], true);

        $candidates[] = [
            'candidate' => $decoded ?: (string)$row['candidate'],
            'sdp_mid' => (string)($row['sdp_mid'] ?? ''),
            'sdp_mline_index' => (int)($row['sdp_mline_index'] ?? 0),
        ];
    }

    extqli_json([
        'success' => true,
        'has_candidates' => true,
        'candidates' => $candidates
    ]);
}

try {
    $pdo = extqli_pdo();

    extqli_ensure_tables($pdo);
    extqli_upgrade_tables($pdo);
    extqli_cleanup($pdo);

    $action = trim((string)($_GET['action'] ?? ''));

    switch ($action) {
        case 'submit_offer':
            extqli_submit_offer($pdo);
            break;

        case 'poll_offer':
            extqli_poll_offer($pdo);
            break;

        case 'submit_answer':
            extqli_submit_answer($pdo);
            break;

        case 'poll_answer':
            extqli_poll_answer($pdo);
            break;

        case 'ice_candidate':
            extqli_submit_ice_candidate($pdo);
            break;

        case 'poll_ice_candidates':
            extqli_poll_ice_candidates($pdo);
            break;

        default:
            extqli_json([
                'success' => false,
                'message' => 'Invalid signaling action.',
                'action' => $action
            ], 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    extqli_json([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], 500);
}