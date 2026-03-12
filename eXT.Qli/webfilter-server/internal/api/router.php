<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../logging/app.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/guard.php';

function render_view(string $file, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require APP_VIEW_PATH . '/' . $file . '.php';
    exit;
}

function handle_route(string $route, string $method): void
{
    if ($route === '/web/assets/app.css') {
        header('Content-Type: text/css; charset=utf-8');
        readfile(APP_WEB_PATH . '/assets/app.css');
        exit;
    }

    if ($route === '/web/assets/app.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(APP_WEB_PATH . '/assets/app.js');
        exit;
    }

    if ($route === '/web/assets/api.js') {
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(APP_WEB_PATH . '/assets/api.js');
        exit;
    }

    if ($route === '/' || $route === '/login') {
        render_view('login', [
            'page' => 'login'
        ]);
    }

    if ($route === '/dashboard') {
        if (empty($_SESSION['admin_id'])) {
            header('Location: /login');
            exit;
        }

        render_view('home', [
            'page' => 'dashboard'
        ]);
    }

    if ($route === '/devices') {
        if (empty($_SESSION['admin_id'])) {
            header('Location: /login');
            exit;
        }

        render_view('devices', [
            'page' => 'devices'
        ]);
    }

    if ($route === '/policies') {
        if (empty($_SESSION['admin_id'])) {
            header('Location: /login');
            exit;
        }

        render_view('policies', [
            'page' => 'policies'
        ]);
    }

    if ($route === '/logs') {
        if (empty($_SESSION['admin_id'])) {
            header('Location: /login');
            exit;
        }

        render_view('logs', [
            'page' => 'logs'
        ]);
    }

    if ($route === '/api/auth/login' && $method === 'POST') {
        require __DIR__ . '/../auth/login.php';
        exit;
    }

    if ($route === '/api/auth/logout' && $method === 'POST') {
        require __DIR__ . '/../auth/logout.php';
        exit;
    }

    if ($route === '/api/agent/enroll' && $method === 'POST') {
        require __DIR__ . '/../agent/enroll.php';
        exit;
    }

    if ($route === '/api/agent/heartbeat' && $method === 'POST') {
        require __DIR__ . '/../agent/heartbeat.php';
        exit;
    }

    if ($route === '/api/policies' && $method === 'GET') {
        require_auth();
        require __DIR__ . '/../policy/list.php';
        exit;
    }

    if ($route === '/api/policies/save' && $method === 'POST') {
        require_auth();
        require __DIR__ . '/../policy/save.php';
        exit;
    }

    if ($route === '/api/rules' && $method === 'GET') {
        require_auth();
        require __DIR__ . '/../rules/list.php';
        exit;
    }

    if ($route === '/api/rules/save' && $method === 'POST') {
        require_auth();
        require __DIR__ . '/../rules/save.php';
        exit;
    }

    if ($route === '/api/devices' && $method === 'GET') {
        require_auth();

        $stmt = db()->query("
            SELECT
                d.id,
                d.device_uuid,
                d.hostname,
                d.ip_address,
                d.operating_system,
                d.agent_version,
                d.status,
                d.last_seen_at,
                p.name AS policy_name
            FROM devices d
            LEFT JOIN device_policy_assignments dpa ON dpa.device_id = d.id
            LEFT JOIN policies p ON p.id = dpa.policy_id
            ORDER BY d.id DESC
        ");

        json_response([
            'status' => 'success',
            'data' => $stmt->fetchAll()
        ]);
    }

    if ($route === '/api/logs' && $method === 'GET') {
        require_auth();

        $stmt = db()->query("
            SELECT
                id,
                device_uuid,
                hostname,
                domain,
                action,
                reason,
                created_at
            FROM block_events
            ORDER BY id DESC
            LIMIT 200
        ");

        json_response([
            'status' => 'success',
            'data' => $stmt->fetchAll()
        ]);
    }

    http_response_code(404);
    echo 'Not Found';
    exit;
}