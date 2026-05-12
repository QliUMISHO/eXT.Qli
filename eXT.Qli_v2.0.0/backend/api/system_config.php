<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT = 3478;

function extqli_config_storage_dir(): string
{
    return dirname(__DIR__) . '/storage';
}

function extqli_config_file(): string
{
    return extqli_config_storage_dir() . '/system_config.json';
}

function extqli_json_ok(array $data = []): void
{
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function extqli_json_fail(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);

    echo json_encode([
        'success' => false,
        'message' => $message
    ] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

function extqli_clean_string($value, int $max = 255): string
{
    $text = trim((string)($value ?? ''));

    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }

    return substr($text, 0, $max);
}

function extqli_get_server_ip(): string
{
    $serverAddr = extqli_clean_string($_SERVER['SERVER_ADDR'] ?? '', 80);

    if ($serverAddr !== '' && $serverAddr !== '127.0.0.1' && $serverAddr !== '::1') {
        return $serverAddr;
    }

    $hostnameIp = trim((string)@shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));

    if ($hostnameIp !== '') {
        return $hostnameIp;
    }

    $host = gethostname();

    if ($host) {
        $resolved = gethostbyname($host);

        if ($resolved && $resolved !== $host) {
            return $resolved;
        }
    }

    return '127.0.0.1';
}

function extqli_default_scan_target(): string
{
    $ip = extqli_get_server_ip();

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
    }

    return $ip;
}

function extqli_default_config(): array
{
    $serverIp = extqli_get_server_ip();

    return [
        'scan_ip' => extqli_default_scan_target(),
        'system_port' => EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT,
        'default_port' => EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT,
        'turn_auto_discovery' => true,
        'turn_ip' => $serverIp,
        'turn_port' => EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT,
        'turn_username' => 'tachyon',
        'turn_password' => '',
        'detected_server_ip' => $serverIp,
        'updated_at' => null
    ];
}

function extqli_load_config(): array
{
    $default = extqli_default_config();
    $file = extqli_config_file();

    if (!is_file($file)) {
        return $default;
    }

    $decoded = json_decode((string)file_get_contents($file), true);

    if (!is_array($decoded)) {
        return $default;
    }

    return array_replace($default, $decoded, [
        'detected_server_ip' => extqli_get_server_ip(),
        'default_port' => EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT
    ]);
}

function extqli_save_config(array $config): void
{
    $dir = extqli_config_storage_dir();

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            extqli_json_fail('Failed to create storage directory.', 500);
        }
    }

    $file = extqli_config_file();

    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($encoded === false) {
        extqli_json_fail('Failed to encode config JSON.', 500);
    }

    if (file_put_contents($file, $encoded . PHP_EOL, LOCK_EX) === false) {
        extqli_json_fail('Failed to write system_config.json.', 500);
    }

    @chmod($file, 0664);
}

function extqli_validate_scan_target(string $value): bool
{
    if ($value === '') {
        return false;
    }

    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return true;
    }

    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/([0-9]|[12][0-9]|3[0-2])$/', $value)) {
        [$ip, $cidr] = explode('/', $value, 2);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    return false;
}

function extqli_validate_port(int $port): bool
{
    return $port >= 1 && $port <= 65535;
}

function extqli_check_tcp(string $host, int $port, int $timeoutSeconds = 3): array
{
    $errno = 0;
    $errstr = '';

    $start = microtime(true);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);

    if (is_resource($socket)) {
        fclose($socket);

        return [
            'ok' => true,
            'latency_ms' => $elapsedMs,
            'message' => 'TCP connection successful. TURN port is reachable.'
        ];
    }

    return [
        'ok' => false,
        'latency_ms' => $elapsedMs,
        'message' => $errstr !== '' ? $errstr : 'TURN server is not reachable on this port.',
        'errno' => $errno
    ];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = extqli_clean_string($_GET['action'] ?? $_POST['action'] ?? 'get', 50);

if ($method === 'GET' && $action === 'get') {
    extqli_json_ok([
        'config' => extqli_load_config()
    ]);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);

if (!is_array($payload)) {
    $payload = [];
}

if ($action === 'autodiscover_turn') {
    $config = extqli_load_config();
    $serverIp = extqli_get_server_ip();

    $config['turn_auto_discovery'] = true;
    $config['turn_ip'] = $serverIp;
    $config['turn_port'] = (int)($config['turn_port'] ?? EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT);
    $config['detected_server_ip'] = $serverIp;
    $config['updated_at'] = date('Y-m-d H:i:s');

    extqli_json_ok([
        'config' => $config,
        'message' => 'TURN IP autodiscovered from server network address.'
    ]);
}

if ($action === 'check_turn') {
    $host = extqli_clean_string($payload['turn_ip'] ?? $_POST['turn_ip'] ?? '', 120);
    $port = (int)($payload['turn_port'] ?? $_POST['turn_port'] ?? EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT);

    if ($host === '') {
        extqli_json_fail('TURN IP is required.');
    }

    if (!extqli_validate_port($port)) {
        extqli_json_fail('Invalid TURN port.');
    }

    $result = extqli_check_tcp($host, $port, 3);

    extqli_json_ok([
        'turn_ip' => $host,
        'turn_port' => $port,
        'check' => $result
    ]);
}

if ($method === 'POST' && $action === 'save') {
    $scanIp = extqli_clean_string($payload['scan_ip'] ?? '', 120);
    $systemPort = (int)($payload['system_port'] ?? EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT);
    $turnIp = extqli_clean_string($payload['turn_ip'] ?? '', 120);
    $turnPort = (int)($payload['turn_port'] ?? EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT);
    $turnUsername = extqli_clean_string($payload['turn_username'] ?? '', 120);
    $turnPassword = extqli_clean_string($payload['turn_password'] ?? '', 255);
    $turnAutoDiscovery = !empty($payload['turn_auto_discovery']);

    if (!extqli_validate_scan_target($scanIp)) {
        extqli_json_fail('Invalid scan IP/subnet. Use an IP address or CIDR format like 10.201.0.0/24.');
    }

    if (!extqli_validate_port($systemPort)) {
        extqli_json_fail('Invalid system port.');
    }

    if ($turnIp === '') {
        extqli_json_fail('TURN IP is required.');
    }

    if (!extqli_validate_port($turnPort)) {
        extqli_json_fail('Invalid TURN port.');
    }

    if ($turnUsername === '') {
        extqli_json_fail('TURN username is required.');
    }

    $config = [
        'scan_ip' => $scanIp,
        'system_port' => $systemPort,
        'default_port' => EXTQLI_SYSTEM_CONFIG_DEFAULT_PORT,
        'turn_auto_discovery' => $turnAutoDiscovery,
        'turn_ip' => $turnIp,
        'turn_port' => $turnPort,
        'turn_username' => $turnUsername,
        'turn_password' => $turnPassword,
        'detected_server_ip' => extqli_get_server_ip(),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    extqli_save_config($config);

    extqli_json_ok([
        'config' => $config,
        'message' => 'System configuration saved.'
    ]);
}

extqli_json_fail('Unknown action.', 404);