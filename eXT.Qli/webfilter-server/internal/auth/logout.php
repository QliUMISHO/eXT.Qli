<?php
declare(strict_types=1);

session_unset();
session_destroy();

json_response([
    'status' => 'success',
    'message' => 'Logged out'
]);