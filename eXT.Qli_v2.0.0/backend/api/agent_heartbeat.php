<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

date_default_timezone_set('Asia/Manila');

function json_ok(array $extra = []): void
{
    echo json_encode(['success' => true] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_fail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function find_config_file(): ?string
{
    $candidates = [
        __DIR__ . '/_bootstrap.php',
        dirname(__DIR__) . '/_bootstrap.php',
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__, 2) . '/config.php',
        dirname(__DIR__, 3) . '/config.php',
        __DIR__ . '/../config.php',
    ];

    foreach ($candidates as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

function db(): mysqli
{
    $configFile = find_config_file();

    if ($configFile) {
        require_once $configFile;
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $mysqli = $GLOBALS['conn'];
    } elseif (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
        $mysqli = $GLOBALS['mysqli'];
    } elseif (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof mysqli) {
        $mysqli = $GLOBALS['db'];
    } elseif (isset($conn) && $conn instanceof mysqli) {
        $mysqli = $conn;
    } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli = $mysqli;
    } elseif (isset($db) && $db instanceof mysqli) {
        $mysqli = $db;
    } else {
        $host = defined('DB_HOST') ? DB_HOST : (defined('DB_SERVER') ? DB_SERVER : '127.0.0.1');
        $user = defined('DB_USER') ? DB_USER : (defined('DB_USERNAME') ? DB_USERNAME : 'root');
        $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
        $name = defined('DB_NAME') ? DB_NAME : (defined('DB_DATABASE') ? DB_DATABASE : '');

        if ($name === '') {
            json_fail('Database name is not configured. Check config.php or _bootstrap.php.', 500);
        }

        $mysqli = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name);
    }

    if ($mysqli->connect_errno) {
        json_fail('Database connection failed: ' . $mysqli->connect_error, 500);
    }

    $mysqli->set_charset('utf8mb4');

    return $mysqli;
}

function table_exists(mysqli $db, string $table): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        json_fail('Table check prepare failed: ' . $db->error, 500);
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function column_exists(mysqli $db, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        json_fail('Column check prepare failed: ' . $db->error, 500);
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function add_column(mysqli $db, string $table, string $column, string $definition): void
{
    if (column_exists($db, $table, $column)) {
        return;
    }

    $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";

    if (!$db->query($sql)) {
        json_fail("Failed to add column `$column` to `$table`: " . $db->error, 500);
    }
}

function ensure_tables(mysqli $db): void
{
    if (!table_exists($db, 'agents')) {
        $sql = "
            CREATE TABLE `agents` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agent_uuid` VARCHAR(120) NOT NULL,
                `agent_token` VARCHAR(190) NULL,
                `hostname` VARCHAR(190) NULL,
                `username` VARCHAR(190) NULL,
                `logged_in_username` VARCHAR(190) NULL,
                `current_user` VARCHAR(190) NULL,
                `display_name` VARCHAR(190) NULL,
                `endpoint_username_output` VARCHAR(190) NULL,
                `username_stdout` VARCHAR(190) NULL,
                `username_probe_output` VARCHAR(190) NULL,
                `local_ip` VARCHAR(80) NULL,
                `mac_address` VARCHAR(80) NULL,
                `os_name` VARCHAR(120) NULL,
                `os_version` VARCHAR(120) NULL,
                `architecture` VARCHAR(120) NULL,
                `screen_width` INT NULL DEFAULT 0,
                `screen_height` INT NULL DEFAULT 0,
                `last_seen` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_agent_uuid` (`agent_uuid`),
                KEY `idx_last_seen` (`last_seen`),
                KEY `idx_local_ip` (`local_ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$db->query($sql)) {
            json_fail('Failed to create agents table: ' . $db->error, 500);
        }
    }

    if (!table_exists($db, 'agent_heartbeats')) {
        $sql = "
            CREATE TABLE `agent_heartbeats` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `agent_uuid` VARCHAR(120) NOT NULL,
                `agent_token` VARCHAR(190) NULL,
                `hostname` VARCHAR(190) NULL,
                `username` VARCHAR(190) NULL,
                `logged_in_username` VARCHAR(190) NULL,
                `current_user` VARCHAR(190) NULL,
                `display_name` VARCHAR(190) NULL,
                `endpoint_username_output` VARCHAR(190) NULL,
                `username_stdout` VARCHAR(190) NULL,
                `username_probe_output` VARCHAR(190) NULL,
                `local_ip` VARCHAR(80) NULL,
                `mac_address` VARCHAR(80) NULL,
                `os_name` VARCHAR(120) NULL,
                `os_version` VARCHAR(120) NULL,
                `architecture` VARCHAR(120) NULL,
                `screen_width` INT NULL DEFAULT 0,
                `screen_height` INT NULL DEFAULT 0,
                `last_seen` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_agent_uuid` (`agent_uuid`),
                KEY `idx_last_seen` (`last_seen`),
                KEY `idx_local_ip` (`local_ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!$db->query($sql)) {
            json_fail('Failed to create agent_heartbeats table: ' . $db->error, 500);
        }
    }

    $commonColumns = [
        'agent_token' => 'VARCHAR(190) NULL',
        'hostname' => 'VARCHAR(190) NULL',
        'username' => 'VARCHAR(190) NULL',
        'logged_in_username' => 'VARCHAR(190) NULL',
        'current_user' => 'VARCHAR(190) NULL',
        'display_name' => 'VARCHAR(190) NULL',
        'endpoint_username_output' => 'VARCHAR(190) NULL',
        'username_stdout' => 'VARCHAR(190) NULL',
        'username_probe_output' => 'VARCHAR(190) NULL',
        'local_ip' => 'VARCHAR(80) NULL',
        'mac_address' => 'VARCHAR(80) NULL',
        'os_name' => 'VARCHAR(120) NULL',
        'os_version' => 'VARCHAR(120) NULL',
        'architecture' => 'VARCHAR(120) NULL',
        'screen_width' => 'INT NULL DEFAULT 0',
        'screen_height' => 'INT NULL DEFAULT 0',
        'last_seen' => 'DATETIME NULL',
        'created_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($commonColumns as $column => $definition) {
        add_column($db, 'agents', $column, $definition);
        add_column($db, 'agent_heartbeats', $column, $definition);
    }

    add_column($db, 'agents', 'updated_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    if (!column_exists($db, 'agents', 'agent_uuid')) {
        json_fail('agents table exists but has no agent_uuid column. Add agent_uuid VARCHAR(120) first.', 500);
    }

    if (!column_exists($db, 'agent_heartbeats', 'agent_uuid')) {
        json_fail('agent_heartbeats table exists but has no agent_uuid column. Add agent_uuid VARCHAR(120) first.', 500);
    }
}

function payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);

    if (is_array($json)) {
        return array_merge($_GET, $_POST, $json);
    }

    return array_merge($_GET, $_POST);
}

function clean($value, int $max = 255): string
{
    $text = trim((string)($value ?? ''));

    if ($text === '') {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($text, 0, $max, 'UTF-8')
        : substr($text, 0, $max);
}

function int_value($value): int
{
    return max(0, (int)($value ?? 0));
}

try {
    $db = db();
    ensure_tables($db);

    $payload = payload();

    $agentUuid = clean(
        $payload['agent_uuid']
            ?? $payload['uuid']
            ?? $payload['client_uuid']
            ?? $payload['endpoint_uuid']
            ?? '',
        120
    );

    if ($agentUuid === '') {
        json_fail('Missing agent_uuid.');
    }

    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $agentUuid)) {
        json_fail('Invalid agent_uuid format.');
    }

    $agentToken = clean($payload['agent_token'] ?? $payload['token'] ?? '', 190);
    $hostname = clean($payload['hostname'] ?? $payload['host_name'] ?? $payload['computer_name'] ?? $payload['device_name'] ?? '', 190);

    $username = clean($payload['username'] ?? $payload['logged_in_username'] ?? $payload['current_user'] ?? $payload['display_name'] ?? '', 190);
    $loggedInUsername = clean($payload['logged_in_username'] ?? $username, 190);
    $currentUser = clean($payload['current_user'] ?? $username, 190);
    $displayName = clean($payload['display_name'] ?? $username, 190);

    $endpointUsernameOutput = clean($payload['endpoint_username_output'] ?? $payload['username_stdout'] ?? $payload['username_probe_output'] ?? $username, 190);
    $usernameStdout = clean($payload['username_stdout'] ?? $endpointUsernameOutput, 190);
    $usernameProbeOutput = clean($payload['username_probe_output'] ?? $endpointUsernameOutput, 190);

    $localIp = clean($payload['local_ip'] ?? $payload['ip_address'] ?? $payload['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''), 80);
    $macAddress = clean($payload['mac_address'] ?? $payload['mac'] ?? '', 80);
    $osName = clean($payload['os_name'] ?? $payload['os'] ?? $payload['platform'] ?? 'Unknown', 120);
    $osVersion = clean($payload['os_version'] ?? $payload['version'] ?? '', 120);
    $architecture = clean($payload['architecture'] ?? $payload['arch'] ?? '', 120);

    $screenWidth = int_value($payload['screen_width'] ?? 0);
    $screenHeight = int_value($payload['screen_height'] ?? 0);
    $now = date('Y-m-d H:i:s');

    $upsertSql = "
        INSERT INTO `agents` (
            `agent_uuid`,
            `agent_token`,
            `hostname`,
            `username`,
            `logged_in_username`,
            `current_user`,
            `display_name`,
            `endpoint_username_output`,
            `username_stdout`,
            `username_probe_output`,
            `local_ip`,
            `mac_address`,
            `os_name`,
            `os_version`,
            `architecture`,
            `screen_width`,
            `screen_height`,
            `last_seen`,
            `created_at`,
            `updated_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            `agent_token` = VALUES(`agent_token`),
            `hostname` = VALUES(`hostname`),
            `username` = VALUES(`username`),
            `logged_in_username` = VALUES(`logged_in_username`),
            `current_user` = VALUES(`current_user`),
            `display_name` = VALUES(`display_name`),
            `endpoint_username_output` = VALUES(`endpoint_username_output`),
            `username_stdout` = VALUES(`username_stdout`),
            `username_probe_output` = VALUES(`username_probe_output`),
            `local_ip` = VALUES(`local_ip`),
            `mac_address` = VALUES(`mac_address`),
            `os_name` = VALUES(`os_name`),
            `os_version` = VALUES(`os_version`),
            `architecture` = VALUES(`architecture`),
            `screen_width` = VALUES(`screen_width`),
            `screen_height` = VALUES(`screen_height`),
            `last_seen` = VALUES(`last_seen`),
            `updated_at` = VALUES(`updated_at`)
    ";

    $stmt = $db->prepare($upsertSql);

    if (!$stmt) {
        json_fail('Failed to prepare agents upsert: ' . $db->error, 500);
    }

    $types = 'sssssssssssssssiiiss';

    $stmt->bind_param(
        $types,
        $agentUuid,
        $agentToken,
        $hostname,
        $username,
        $loggedInUsername,
        $currentUser,
        $displayName,
        $endpointUsernameOutput,
        $usernameStdout,
        $usernameProbeOutput,
        $localIp,
        $macAddress,
        $osName,
        $osVersion,
        $architecture,
        $screenWidth,
        $screenHeight,
        $now,
        $now,
        $now
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_fail('Failed to save agent: ' . $error, 500);
    }

    $stmt->close();

    $heartbeatSql = "
        INSERT INTO `agent_heartbeats` (
            `agent_uuid`,
            `agent_token`,
            `hostname`,
            `username`,
            `logged_in_username`,
            `current_user`,
            `display_name`,
            `endpoint_username_output`,
            `username_stdout`,
            `username_probe_output`,
            `local_ip`,
            `mac_address`,
            `os_name`,
            `os_version`,
            `architecture`,
            `screen_width`,
            `screen_height`,
            `last_seen`,
            `created_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($heartbeatSql);

    if (!$stmt) {
        json_fail('Failed to prepare heartbeat insert: ' . $db->error, 500);
    }

    $types = 'sssssssssssssssii ss';
    $types = str_replace(' ', '', $types);

    $stmt->bind_param(
        $types,
        $agentUuid,
        $agentToken,
        $hostname,
        $username,
        $loggedInUsername,
        $currentUser,
        $displayName,
        $endpointUsernameOutput,
        $usernameStdout,
        $usernameProbeOutput,
        $localIp,
        $macAddress,
        $osName,
        $osVersion,
        $architecture,
        $screenWidth,
        $screenHeight,
        $now,
        $now
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        json_fail('Failed to insert heartbeat: ' . $error, 500);
    }

    $stmt->close();

    json_ok([
        'message' => 'Heartbeat saved.',
        'agent_uuid' => $agentUuid,
        'hostname' => $hostname,
        'username' => $username,
        'local_ip' => $localIp,
        'last_seen' => $now,
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}