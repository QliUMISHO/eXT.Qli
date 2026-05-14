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

function json_fail(string $message, int $status = 500, array $extra = []): void
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
            json_fail('Database name is not configured. Check config.php or _bootstrap.php.');
        }

        $mysqli = @new mysqli((string)$host, (string)$user, (string)$pass, (string)$name);
    }

    if ($mysqli->connect_errno) {
        json_fail('Database connection failed: ' . $mysqli->connect_error);
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
        json_fail('Table check prepare failed: ' . $db->error);
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
        json_fail('Column check prepare failed: ' . $db->error);
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
        json_fail("Failed to add column `$column` to `$table`: " . $db->error);
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
            json_fail('Failed to create agents table: ' . $db->error);
        }
    }

    $columns = [
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
        'updated_at' => 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($columns as $column => $definition) {
        add_column($db, 'agents', $column, $definition);
    }

    if (!column_exists($db, 'agents', 'agent_uuid')) {
        json_fail('agents table exists but has no agent_uuid column.');
    }
}

function online_from_last_seen(?string $lastSeen, int $ttlSeconds): bool
{
    if (!$lastSeen) {
        return false;
    }

    $ts = strtotime($lastSeen);

    if (!$ts) {
        return false;
    }

    return (time() - $ts) <= $ttlSeconds;
}

try {
    $db = db();
    ensure_tables($db);

    $ttlSeconds = isset($_GET['ttl']) ? max(15, min(3600, (int)$_GET['ttl'])) : 180;
    $onlineOnly = isset($_GET['online_only']) ? filter_var($_GET['online_only'], FILTER_VALIDATE_BOOL) : false;
    $limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 500;

    $sql = "
        SELECT
            `agent_uuid`,
            COALESCE(`agent_token`, '') AS `agent_token`,
            COALESCE(`hostname`, '') AS `hostname`,
            COALESCE(`username`, '') AS `username`,
            COALESCE(`logged_in_username`, '') AS `logged_in_username`,
            COALESCE(`current_user`, '') AS `current_user`,
            COALESCE(`display_name`, '') AS `display_name`,
            COALESCE(`endpoint_username_output`, '') AS `endpoint_username_output`,
            COALESCE(`username_stdout`, '') AS `username_stdout`,
            COALESCE(`username_probe_output`, '') AS `username_probe_output`,
            COALESCE(`local_ip`, '') AS `local_ip`,
            COALESCE(`mac_address`, '') AS `mac_address`,
            COALESCE(`os_name`, 'Unknown') AS `os_name`,
            COALESCE(`os_version`, '') AS `os_version`,
            COALESCE(`architecture`, '') AS `architecture`,
            COALESCE(`screen_width`, 0) AS `screen_width`,
            COALESCE(`screen_height`, 0) AS `screen_height`,
            `last_seen`,
            `created_at`,
            `updated_at`
        FROM `agents`
        WHERE `agent_uuid` IS NOT NULL
          AND `agent_uuid` <> ''
        ORDER BY `last_seen` DESC, `updated_at` DESC, `id` DESC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        json_fail('Failed to prepare agents query: ' . $db->error);
    }

    $stmt->bind_param('i', $limit);
    $stmt->execute();

    $res = $stmt->get_result();
    $data = [];

    while ($row = $res->fetch_assoc()) {
        $row['screen_width'] = (int)($row['screen_width'] ?? 0);
        $row['screen_height'] = (int)($row['screen_height'] ?? 0);
        $row['is_online'] = online_from_last_seen((string)($row['last_seen'] ?? ''), $ttlSeconds);
        $row['source'] = 'agents';

        if ($onlineOnly && !$row['is_online']) {
            continue;
        }

        $data[] = $row;
    }

    $stmt->close();

    usort($data, static function (array $a, array $b): int {
        $ao = (bool)($a['is_online'] ?? false);
        $bo = (bool)($b['is_online'] ?? false);

        if ($ao !== $bo) {
            return $ao ? -1 : 1;
        }

        $at = strtotime((string)($a['last_seen'] ?? '')) ?: 0;
        $bt = strtotime((string)($b['last_seen'] ?? '')) ?: 0;

        return $bt <=> $at;
    });

    $onlineCount = 0;

    foreach ($data as $row) {
        if (!empty($row['is_online'])) {
            $onlineCount++;
        }
    }

    json_ok([
        'data' => $data,
        'count' => count($data),
        'online_count' => $onlineCount,
        'ttl_seconds' => $ttlSeconds,
        'server_time' => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    json_fail($e->getMessage(), 500);
}