<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../configs/app.php';
require_once __DIR__ . '/../../internal/api/router.php';

$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

handle_route($route, $method);