<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

function extqli_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '127.0.0.1');

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/eXT.Qli_preprod/backend/api'));
    $basePath = preg_replace('#/backend/api$#', '', $scriptDir);

    if ($basePath === '' || $basePath === '.') {
        $basePath = '/eXT.Qli_preprod';
    }

    return $scheme . '://' . $host . $basePath;
}

function extqli_runtime_dir(): string
{
    return dirname(__DIR__) . '/runtime/native_viewer';
}

function extqli_native_viewer_path(): string
{
    return dirname(__DIR__, 2) . '/native_viewer/extqli_native_viewer.py';
}

function extqli_find_python(): string
{
    $candidates = [
        dirname(__DIR__, 2) . '/native_viewer/venv/bin/python3',
        dirname(__DIR__, 2) . '/venv/bin/python3',
        '/usr/bin/python3',
        '/usr/local/bin/python3',
        'python3'
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === 'python3') {
            return $candidate;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'python3';
}

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);

    if (!is_array($payload)) {
        extqli_json_fail('Invalid JSON body.');
    }

    $agentUuid = extqli_clean_string($payload['agent_uuid'] ?? '', 120);

    if ($agentUuid === '') {
        extqli_json_fail('Missing agent_uuid.');
    }

    if (!preg_match('/^[a-zA-Z0-9._:-]+$/', $agentUuid)) {
        extqli_json_fail('Invalid agent_uuid format.');
    }

    $viewerScript = extqli_native_viewer_path();

    if (!is_file($viewerScript)) {
        extqli_json_fail('Native viewer script not found.', 500, [
            'expected_path' => $viewerScript
        ]);
    }

    $runtimeDir = extqli_runtime_dir();

    if (!is_dir($runtimeDir)) {
        if (!mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
            extqli_json_fail('Failed to create runtime directory.', 500);
        }
    }

    $python = extqli_find_python();
    $baseUrl = extqli_base_url();
    $viewerId = 'native-' . bin2hex(random_bytes(6)) . '-' . time();
    $logFile = $runtimeDir . '/native_viewer_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $agentUuid) . '_' . date('Ymd_His') . '.log';

    $cmd = sprintf(
        'nohup %s %s --base-url %s --agent-uuid %s --viewer-id %s > %s 2>&1 & echo $!',
        escapeshellcmd($python),
        escapeshellarg($viewerScript),
        escapeshellarg($baseUrl),
        escapeshellarg($agentUuid),
        escapeshellarg($viewerId),
        escapeshellarg($logFile)
    );

    $pid = trim((string)shell_exec($cmd));

    if ($pid === '') {
        extqli_json_fail('Native viewer process did not return a PID.', 500);
    }

    extqli_json_ok([
        'message' => 'Native viewer launch requested.',
        'agent_uuid' => $agentUuid,
        'viewer_id' => $viewerId,
        'pid' => $pid,
        'log_file' => $logFile,
        'command_hint' => $python . ' ' . $viewerScript . ' --base-url ' . $baseUrl . ' --agent-uuid ' . $agentUuid
    ]);
} catch (Throwable $e) {
    extqli_json_fail($e->getMessage(), 500);
}