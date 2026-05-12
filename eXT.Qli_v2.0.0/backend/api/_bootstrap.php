<?php
declare(strict_types=1);

/**
 * Shared API bootstrap.
 * Location:
 * /var/www/html/eXT.Qli_preprod/backend/api/_bootstrap.php
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

$root = dirname(__DIR__, 2);

$configCandidates = [
    $root . '/config/config.php',
    $root . '/config.php',
    dirname($root) . '/config.php',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/eXT.Qli_preprod/config/config.php',
    ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/eXT.Qli_preprod/config.php',
];

foreach ($configCandidates as $configPath) {
    if ($configPath && is_file($configPath)) {
        require_once $configPath;
        break;
    }
}

require_once $root . '/backend/lib/Database.php';
require_once $root . '/backend/lib/Response.php';

function extqli_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);

    if (is_array($json)) {
        return $json;
    }

    return $_POST + $_GET;
}

function extqli_expected_token(): string
{
    if (defined('AGENT_SHARED_TOKEN')) {
        return (string)AGENT_SHARED_TOKEN;
    }

    return getenv('EXTQLI_AGENT_SHARED_TOKEN') ?: 'extqli_@2026token$$';
}

function extqli_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        Response::json([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }
}

function extqli_require_token(array $input): void
{
    $provided = trim((string)($input['shared_token'] ?? ($_SERVER['HTTP_X_EXTQLI_AGENT_TOKEN'] ?? '')));
    $expected = extqli_expected_token();

    if (!hash_equals($expected, $provided)) {
        Response::json([
            'success' => false,
            'message' => 'Unauthorized token.'
        ], 401);
    }
}