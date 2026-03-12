<?php
declare(strict_types=1);

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

define('APP_NAME', getenv('APP_NAME') ?: 'WebFilter Server');
define('APP_ENV', getenv('APP_ENV') ?: 'local');
define('APP_DEBUG', (getenv('APP_DEBUG') ?: 'true') === 'true');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');
define('APP_KEY', getenv('APP_KEY') ?: 'webfilter-local-key');
define('APP_VIEW_PATH', realpath(__DIR__ . '/../internal/dashboard'));
define('APP_WEB_PATH', realpath(__DIR__ . '/../web'));