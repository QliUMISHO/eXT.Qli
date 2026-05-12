<?php
declare(strict_types=1);

/**
 * eXT.Qli — Save Agent Identity API
 * Path:
 *   /var/www/html/eXT.Qli_preprod/backend/api/save_agent_identity.php
 *
 * Purpose:
 *   Receives username/identity data from the WebRTC data channel and stores it
 *   against the matching agent_uuid.
 *
 * Fix:
 *   Uses backend/api/_bootstrap.php first instead of loading Database.php alone.
 *   This prevents "Database constants are not defined" errors.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const EXTQLI_SHARED_TOKEN = 'extqli_@2026token$$';

function extqli_json_ok(array $data = []): void
{
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function extqli_json_fail(string $message, int $statusCode = 400, array $extra = []): void
{
    http_response_code($statusCode);

    echo json_encode([
        'success' => false,
        'message' => $message
    ] + $extra, JSON_UNESCAPED_UNICODE);

    exit;
}

function extqli_clean_string($value, int $maxLength = 255): string
{
    $text = trim((string)($value ?? ''));

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength, 'UTF-8');
    }

    return substr($text, 0, $maxLength);
}

function extqli_load_bootstrap(): void
{
    $bootstrap = __DIR__ . '/_bootstrap.php';

    if (is_file($bootstrap)) {
        require_once $bootstrap;
        return;
    }

    $fallbacks = [
        __DIR__ . '/../../config.php',
        __DIR__ . '/../../../config.php',
        dirname(__DIR__, 2) . '/config.php',
        dirname(__DIR__, 3) . '/config.php',
        dirname(__DIR__, 2) . '/config/config.php',
        dirname(__DIR__, 3) . '/config/config.php',
    ];

    foreach ($fallbacks as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }

    extqli_json_fail('Bootstrap/config file not found.', 500, [
        'expected' => $bootstrap
    ]);
}

function extqli_get_mysqli_connection(): ?mysqli
{
    foreach (['conn', 'mysqli', 'db'] as $varName) {
        if (isset($GLOBALS[$varName]) && $GLOBALS[$varName] instanceof mysqli) {
            return $GLOBALS[$varName];
        }
    }

    if (class_exists('Database')) {
        foreach (['connect', 'connection', 'getConnection', 'getInstance'] as $method) {
            if (method_exists('Database', $method)) {
                try {
                    $candidate = Database::$method();

                    if ($candidate instanceof mysqli) {
                        return $candidate;
                    }

                    if (is_object($candidate) && method_exists($candidate, 'getConnection')) {
                        $inner = $candidate->getConnection();

                        if ($inner instanceof mysqli) {
                            return $inner;
                        }
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
    }

    return null;
}

function extqli_get_pdo_connection(): ?PDO
{
    foreach (['pdo', 'dbh'] as $varName) {
        if (isset($GLOBALS[$varName]) && $GLOBALS[$varName] instanceof PDO) {
            return $GLOBALS[$varName];
        }
    }

    if (class_exists('Database')) {
        foreach (['connect', 'connection', 'getConnection', 'getInstance'] as $method) {
            if (method_exists('Database', $method)) {
                try {
                    $candidate = Database::$method();

                    if ($candidate instanceof PDO) {
                        return $candidate;
                    }

                    if (is_object($candidate) && method_exists($candidate, 'getConnection')) {
                        $inner = $candidate->getConnection();

                        if ($inner instanceof PDO) {
                            return $inner;
                        }
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
    }

    return null;
}

function extqli_mysqli_table_exists(mysqli $db, string $table): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function extqli_mysqli_column_exists(mysqli $db, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function extqli_mysqli_find_agent_table(mysqli $db): ?string
{
    $preferredTables = [
        'agents',
        'extqli_agents',
        'agent_clients',
        'clients',
        'endpoints'
    ];

    foreach ($preferredTables as $table) {
        if (
            extqli_mysqli_table_exists($db, $table) &&
            extqli_mysqli_column_exists($db, $table, 'agent_uuid')
        ) {
            return $table;
        }
    }

    $sql = "
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'agent_uuid'
        ORDER BY
            CASE
                WHEN TABLE_NAME = 'agents' THEN 1
                WHEN TABLE_NAME = 'extqli_agents' THEN 2
                WHEN TABLE_NAME LIKE '%agent%' THEN 3
                ELSE 9
            END,
            TABLE_NAME ASC
        LIMIT 1
    ";

    $res = $db->query($sql);

    if ($res && ($row = $res->fetch_assoc())) {
        return (string)$row['TABLE_NAME'];
    }

    return null;
}

function extqli_mysqli_update_identity(mysqli $db, string $agentUuid, string $username, array $payload): array
{
    $db->set_charset('utf8mb4');

    $table = extqli_mysqli_find_agent_table($db);

    if (!$table) {
        extqli_json_fail('No agent table with agent_uuid column was found.', 500);
    }

    $candidateColumns = [
        'username' => $username,
        'logged_in_username' => $username,
        'current_user' => $username,
        'display_name' => $username,
        'endpoint_username_output' => $username,
        'username_stdout' => $username,
        'username_probe_output' => $username,
        'hostname' => extqli_clean_string($payload['hostname'] ?? '', 255),
        'identity_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'updated_at' => '__NOW__',
        'last_seen' => '__NOW__'
    ];

    $setParts = [];
    $values = [];
    $types = '';

    foreach ($candidateColumns as $column => $value) {
        if (!extqli_mysqli_column_exists($db, $table, $column)) {
            continue;
        }

        if ($value === '') {
            continue;
        }

        if ($value === '__NOW__') {
            $setParts[] = "`{$column}` = NOW()";
            continue;
        }

        $setParts[] = "`{$column}` = ?";
        $values[] = $value;
        $types .= 's';
    }

    if (!$setParts) {
        extqli_json_fail('No compatible identity columns found on table: ' . $table, 500);
    }

    $sql = "
        UPDATE `{$table}`
        SET " . implode(', ', $setParts) . "
        WHERE `agent_uuid` = ?
        LIMIT 1
    ";

    $types .= 's';
    $values[] = $agentUuid;

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        extqli_json_fail('Prepare failed: ' . $db->error, 500, [
            'table' => $table
        ]);
    }

    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();

        extqli_json_fail('Execute failed: ' . $err, 500, [
            'table' => $table
        ]);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    return [
        'table' => $table,
        'affected_rows' => $affected
    ];
}

function extqli_pdo_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");

    $stmt->execute([$table]);

    return (bool)$stmt->fetchColumn();
}

function extqli_pdo_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");

    $stmt->execute([$table, $column]);

    return (bool)$stmt->fetchColumn();
}

function extqli_pdo_find_agent_table(PDO $pdo): ?string
{
    $preferredTables = [
        'agents',
        'extqli_agents',
        'agent_clients',
        'clients',
        'endpoints'
    ];

    foreach ($preferredTables as $table) {
        if (
            extqli_pdo_table_exists($pdo, $table) &&
            extqli_pdo_column_exists($pdo, $table, 'agent_uuid')
        ) {
            return $table;
        }
    }

    $stmt = $pdo->query("
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'agent_uuid'
        ORDER BY
            CASE
                WHEN TABLE_NAME = 'agents' THEN 1
                WHEN TABLE_NAME = 'extqli_agents' THEN 2
                WHEN TABLE_NAME LIKE '%agent%' THEN 3
                ELSE 9
            END,
            TABLE_NAME ASC
        LIMIT 1
    ");

    $table = $stmt ? $stmt->fetchColumn() : false;

    return $table ? (string)$table : null;
}

function extqli_pdo_update_identity(PDO $pdo, string $agentUuid, string $username, array $payload): array
{
    $table = extqli_pdo_find_agent_table($pdo);

    if (!$table) {
        extqli_json_fail('No agent table with agent_uuid column was found.', 500);
    }

    $candidateColumns = [
        'username' => $username,
        'logged_in_username' => $username,
        'current_user' => $username,
        'display_name' => $username,
        'endpoint_username_output' => $username,
        'username_stdout' => $username,
        'username_probe_output' => $username,
        'hostname' => extqli_clean_string($payload['hostname'] ?? '', 255),
        'identity_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'updated_at' => '__NOW__',
        'last_seen' => '__NOW__'
    ];

    $setParts = [];
    $values = [];

    foreach ($candidateColumns as $column => $value) {
        if (!extqli_pdo_column_exists($pdo, $table, $column)) {
            continue;
        }

        if ($value === '') {
            continue;
        }

        if ($value === '__NOW__') {
            $setParts[] = "`{$column}` = NOW()";
            continue;
        }

        $setParts[] = "`{$column}` = ?";
        $values[] = $value;
    }

    if (!$setParts) {
        extqli_json_fail('No compatible identity columns found on table: ' . $table, 500);
    }

    $values[] = $agentUuid;

    $sql = "
        UPDATE `{$table}`
        SET " . implode(', ', $setParts) . "
        WHERE `agent_uuid` = ?
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return [
        'table' => $table,
        'affected_rows' => $stmt->rowCount()
    ];
}

try {
    extqli_load_bootstrap();

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);

    if (!is_array($payload)) {
        extqli_json_fail('Invalid JSON body.');
    }

    $token = (string)($payload['shared_token'] ?? '');

    if ($token !== EXTQLI_SHARED_TOKEN) {
        extqli_json_fail('Invalid shared token.', 403);
    }

    $agentUuid = extqli_clean_string($payload['agent_uuid'] ?? '', 120);
    $username = extqli_clean_string(
        $payload['username']
            ?? $payload['logged_in_username']
            ?? $payload['endpoint_username_output']
            ?? $payload['username_stdout']
            ?? $payload['current_user']
            ?? $payload['display_name']
            ?? '',
        255
    );

    if ($agentUuid === '') {
        extqli_json_fail('Missing agent_uuid.');
    }

    if ($username === '') {
        extqli_json_fail('Missing username.');
    }

    $mysqli = extqli_get_mysqli_connection();

    if ($mysqli instanceof mysqli) {
        $result = extqli_mysqli_update_identity($mysqli, $agentUuid, $username, $payload);

        extqli_json_ok([
            'agent_uuid' => $agentUuid,
            'username' => $username,
            'driver' => 'mysqli',
            'table' => $result['table'],
            'affected_rows' => $result['affected_rows']
        ]);
    }

    $pdo = extqli_get_pdo_connection();

    if ($pdo instanceof PDO) {
        $result = extqli_pdo_update_identity($pdo, $agentUuid, $username, $payload);

        extqli_json_ok([
            'agent_uuid' => $agentUuid,
            'username' => $username,
            'driver' => 'pdo',
            'table' => $result['table'],
            'affected_rows' => $result['affected_rows']
        ]);
    }

    extqli_json_fail('No mysqli/PDO database connection found after bootstrap.', 500);
} catch (Throwable $e) {
    extqli_json_fail($e->getMessage(), 500);
}