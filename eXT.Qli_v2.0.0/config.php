<?php
declare(strict_types=1);

/**
 * eXT.Qli Preprod Config
 * Location:
 * /var/www/html/eXT.Qli_preprod/config/config.php
 */

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'eXT.Qli Network Scanner');
define('APP_URL', '/eXT.Qli_preprod');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ext_qli');
define('DB_USER', 'root');

/**
 * Put your real DB password here.
 * Do not commit this file publicly.
 */
define('DB_PASS', 'TachyonDragon107');

define('DB_PORT', 3306);

define('SCAN_TIMEOUT', 1);
define('ALLOW_ONLY_PRIVATE_SUBNETS', false);

define('AGENT_SHARED_TOKEN', 'extqli_@2026token$$');
define('EXTQLI_BASE_PATH', '/eXT.Qli_preprod');
define('EXTQLI_SERVER_HOST', '10.201.0.254');