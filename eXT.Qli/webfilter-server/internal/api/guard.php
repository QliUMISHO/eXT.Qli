<?php
declare(strict_types=1);

function require_auth(): void
{
    if (empty($_SESSION['admin_id'])) {
        json_response([
            'status' => 'error',
            'message' => 'Unauthorized'
        ], 401);
    }
}