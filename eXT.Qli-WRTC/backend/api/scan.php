<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_target(string $target): string
{
    $target = trim($target);

    if ($target === '') {
        return '';
    }

    if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/', $target)) {
        return '';
    }

    $parts = explode('/', $target);
    $ip = $parts[0];

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return '';
    }

    $octets = explode('.', $ip);
    foreach ($octets as $octet) {
        $num = (int)$octet;
        if ($num < 0 || $num > 255) {
            return '';
        }
    }

    if (isset($parts[1])) {
        $cidr = (int)$parts[1];
        if ($cidr < 0 || $cidr > 32) {
            return '';
        }
    }

    return $target;
}

function is_exec_available(): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = ini_get('disable_functions');
    if (!$disabled) {
        return true;
    }

    $disabledList = array_map('trim', explode(',', $disabled));
    return !in_array('exec', $disabledList, true);
}

function find_nmap_binary(): ?string
{
    $candidates = [
        '/usr/bin/nmap',
        '/bin/nmap',
        '/usr/local/bin/nmap',
        '/snap/bin/nmap',
    ];

    foreach ($candidates as $bin) {
        if (is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }

    $output = [];
    $exitCode = 1;
    @exec('command -v nmap 2>/dev/null', $output, $exitCode);

    if ($exitCode === 0 && !empty($output[0])) {
        $bin = trim($output[0]);
        if ($bin !== '' && is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }

    return null;
}

function ensure_log_dir(): string
{
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    return $logDir;
}

function write_log_file(string $filename, string $content): void
{
    $logDir = ensure_log_dir();
    @file_put_contents($logDir . '/' . $filename, $content);
}

function run_command(string $command): array
{
    $outputLines = [];
    $exitCode = 0;
    exec($command, $outputLines, $exitCode);

    return [
        'command' => $command,
        'output' => implode("\n", $outputLines),
        'exit_code' => $exitCode,
    ];
}

function parse_nmap_normal_output(string $output): array
{
    $hosts = [];
    $lines = preg_split('/\r\n|\r|\n/', $output);
    $currentIp = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^Nmap scan report for (.+?) \(([0-9.]+)\)$/', $trimmed, $m)) {
            $hostname = trim($m[1]);
            $ip = trim($m[2]);

            $currentIp = $ip;
            if (!isset($hosts[$ip])) {
                $hosts[$ip] = [
                    'ip' => $ip,
                    'hostname' => $hostname,
                    'status' => 'Up',
                    'mac' => '',
                    'vendor' => '',
                    'ports' => [],
                    'open_ports_count' => 0,
                ];
            } else {
                if ($hosts[$ip]['hostname'] === '') {
                    $hosts[$ip]['hostname'] = $hostname;
                }
            }
            continue;
        }

        if (preg_match('/^Nmap scan report for ([0-9.]+)$/', $trimmed, $m)) {
            $ip = trim($m[1]);

            $currentIp = $ip;
            if (!isset($hosts[$ip])) {
                $hosts[$ip] = [
                    'ip' => $ip,
                    'hostname' => '',
                    'status' => 'Up',
                    'mac' => '',
                    'vendor' => '',
                    'ports' => [],
                    'open_ports_count' => 0,
                ];
            }
            continue;
        }

        if ($currentIp === null || !isset($hosts[$currentIp])) {
            continue;
        }

        if (stripos($trimmed, 'Host is up') === 0) {
            $hosts[$currentIp]['status'] = 'Up';
            continue;
        }

        if (preg_match('/^([0-9]+\/tcp)\s+open\s+(.+)$/i', $trimmed, $m)) {
            $hosts[$currentIp]['ports'][] = $m[1] . ' (' . trim($m[2]) . ')';
            continue;
        }

        if (preg_match('/^([0-9]+\/udp)\s+open\s+(.+)$/i', $trimmed, $m)) {
            $hosts[$currentIp]['ports'][] = $m[1] . ' (' . trim($m[2]) . ')';
            continue;
        }

        if (preg_match('/^MAC Address:\s+([0-9A-F:]{17})(?:\s+\((.*?)\))?/i', $trimmed, $m)) {
            $hosts[$currentIp]['mac'] = strtoupper($m[1]);
            $hosts[$currentIp]['vendor'] = isset($m[2]) ? trim($m[2]) : '';
            continue;
        }
    }

    foreach ($hosts as &$host) {
        $host['ports'] = array_values(array_unique($host['ports']));
        $host['open_ports_count'] = count($host['ports']);
    }
    unset($host);

    uasort($hosts, function ($a, $b) {
        return ip2long($a['ip']) <=> ip2long($b['ip']);
    });

    return array_values($hosts);
}

function is_private_ipv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    if (preg_match('/^10\./', $ip)) {
        return true;
    }

    if (preg_match('/^192\.168\./', $ip)) {
        return true;
    }

    if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip)) {
        return true;
    }

    return false;
}

function choose_best_detected_ip(array $ips): ?string
{
    foreach ($ips as $ip) {
        if (is_private_ipv4($ip) && $ip !== '127.0.0.1') {
            return $ip;
        }
    }

    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $ip !== '127.0.0.1') {
            return $ip;
        }
    }

    return null;
}

function build_cidr_from_ip(string $ip): string
{
    $octets = explode('.', $ip);
    return $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';
}

function detect_server_ipv4_from_hostname_i(): array
{
    $run = run_command('hostname -I 2>&1');
    $output = trim($run['output']);

    if ($run['exit_code'] !== 0 || $output === '') {
        return [
            'success' => false,
            'message' => 'hostname -I returned no usable output.',
            'command' => $run['command'],
            'raw_output' => $run['output'],
            'exit_code' => $run['exit_code'],
            'all_ipv4' => [],
            'detected_ip' => null,
            'detected_cidr' => null,
        ];
    }

    $tokens = preg_split('/\s+/', $output);
    $ipv4s = [];

    foreach ($tokens as $token) {
        $token = trim($token);
        if (filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipv4s[] = $token;
        }
    }

    $ipv4s = array_values(array_unique($ipv4s));
    $selectedIp = choose_best_detected_ip($ipv4s);

    if ($selectedIp === null) {
        return [
            'success' => false,
            'message' => 'No usable IPv4 address found in hostname -I output.',
            'command' => $run['command'],
            'raw_output' => $run['output'],
            'exit_code' => $run['exit_code'],
            'all_ipv4' => $ipv4s,
            'detected_ip' => null,
            'detected_cidr' => null,
        ];
    }

    return [
        'success' => true,
        'message' => 'Server IPv4 detected successfully.',
        'command' => $run['command'],
        'raw_output' => $run['output'],
        'exit_code' => $run['exit_code'],
        'all_ipv4' => $ipv4s,
        'detected_ip' => $selectedIp,
        'detected_cidr' => build_cidr_from_ip($selectedIp),
    ];
}

try {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        json_response([
            'success' => false,
            'message' => 'Invalid JSON request body.'
        ], 400);
    }

    if (!is_exec_available()) {
        json_response([
            'success' => false,
            'message' => 'PHP exec() is disabled on the server.',
            'diagnostics' => [
                'disable_functions' => ini_get('disable_functions'),
            ]
        ], 500);
    }

    $nmap = find_nmap_binary();
    if ($nmap === null) {
        json_response([
            'success' => false,
            'message' => 'nmap binary not found on the server.',
            'diagnostics' => [
                'checked_paths' => [
                    '/usr/bin/nmap',
                    '/bin/nmap',
                    '/usr/local/bin/nmap',
                    '/snap/bin/nmap',
                ],
                'server_path' => getenv('PATH') ?: '',
            ]
        ], 500);
    }

    $detectMode = !empty($payload['detect']) || (($payload['mode'] ?? '') === 'detect');
    $target = '';
    $displayTarget = '';

    $detectInfo = [
        'success' => false,
        'message' => '',
        'command' => '',
        'raw_output' => '',
        'exit_code' => null,
        'all_ipv4' => [],
        'detected_ip' => null,
        'detected_cidr' => null,
    ];

    if ($detectMode) {
        $detectInfo = detect_server_ipv4_from_hostname_i();

        if (!$detectInfo['success']) {
            json_response([
                'success' => false,
                'message' => $detectInfo['message'],
                'detect' => $detectInfo,
            ], 500);
        }

        $target = $detectInfo['detected_ip'];
        $displayTarget = $detectInfo['detected_cidr'];
    } else {
        $target = normalize_target((string)($payload['target'] ?? ''));
        if ($target === '') {
            json_response([
                'success' => false,
                'message' => 'Invalid target. Use IPv4 or CIDR like 10.201.31.0/24'
            ], 422);
        }

        $displayTarget = $target;
    }

    $start = microtime(true);
    $allRawOutputs = [];
    $results = [];
    $exitCodes = [];

    if ($detectMode) {
        $allRawOutputs[] = "===== DETECT COMMAND =====\n" . $detectInfo['command'];
        $allRawOutputs[] = "===== DETECT OUTPUT =====\n" . $detectInfo['raw_output'];
        $allRawOutputs[] = "===== COMPUTED CIDR =====\n" . $detectInfo['detected_cidr'];
        $allRawOutputs[] = "===== NMAP TARGET =====\n" . $detectInfo['detected_ip'];
    }

    $singleCommand = sprintf(
        '%s -n -T4 --top-ports 1000 %s 2>&1',
        escapeshellarg($nmap),
        escapeshellarg($target)
    );

    $singleRun = run_command($singleCommand);
    $allRawOutputs[] = "===== SCAN COMMAND =====\n" . $singleRun['command'];
    $allRawOutputs[] = "===== SCAN OUTPUT =====\n" . $singleRun['output'];
    $exitCodes[] = $singleRun['exit_code'];

    $parsed = parse_nmap_normal_output($singleRun['output']);
    foreach ($parsed as $host) {
        $results[$host['ip']] = $host;
    }

    if (empty($results) && filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $results[$target] = [
            'ip' => $target,
            'hostname' => '',
            'status' => 'Unknown',
            'mac' => '',
            'vendor' => '',
            'ports' => [],
            'open_ports_count' => 0,
        ];
    }

    ksort($results, SORT_NATURAL);
    $results = array_values($results);

    $rawOutput = implode("\n\n", $allRawOutputs);
    $elapsed = round(microtime(true) - $start, 2) . 's';

    write_log_file('last_scan_output.txt', $rawOutput);

    $hostsUp = count(array_filter($results, function ($row) {
        return strtoupper((string)($row['status'] ?? '')) === 'UP';
    }));

    if ($hostsUp === 0 && !empty($results)) {
        $hostsUp = count($results);
    }

    $hostsWithPorts = count(array_filter($results, function ($row) {
        return !empty($row['ports']);
    }));

    json_response([
        'success' => true,
        'message' => !empty($results) ? 'Scan completed successfully.' : 'Scan completed but no live hosts were found.',
        'target' => $displayTarget,
        'actual_scan_target' => $target,
        'elapsed' => $elapsed,
        'hosts_up' => $hostsUp,
        'hosts_with_ports' => $hostsWithPorts,
        'results' => $results,
        'raw_output' => $rawOutput,
        'exit_code' => max($exitCodes ?: [0]),
        'nmap_path' => $nmap,
        'log_file' => '/eXT.Qli/backend/logs/last_scan_output.txt',
        'detect' => $detectInfo
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ], 500);
}