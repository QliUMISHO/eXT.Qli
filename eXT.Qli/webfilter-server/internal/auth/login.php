<?php
declare(strict_types=1);

$data = input_json();

$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');

if ($username === '' || $password === '') {
    json_response([
        'status' => 'error',
        'message' => 'Username and password are required'
    ], 422);
}

$stmt = db()->prepare("SELECT id, username, password_hash, full_name FROM admins WHERE username = :username LIMIT 1");
$stmt->execute(['username' => $username]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    app_log('auth', 'Failed login', ['username' => $username]);

    json_response([
        'status' => 'error',
        'message' => 'Invalid credentials'
    ], 401);
}

$_SESSION['admin_id'] = (int)$admin['id'];
$_SESSION['admin_name'] = $admin['full_name'];
$_SESSION['admin_username'] = $admin['username'];

app_log('auth', 'Login success', ['admin_id' => $admin['id']]);

json_response([
    'status' => 'success',
    'message' => 'Login successful',
    'data' => [
        'id' => (int)$admin['id'],
        'full_name' => $admin['full_name'],
        'username' => $admin['username']
    ]
]);