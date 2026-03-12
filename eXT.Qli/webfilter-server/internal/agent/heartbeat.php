<?php
declare(strict_types=1);

$data = input_json();

$token = trim((string)($data['token'] ?? ''));
$ipAddress = trim((string)($data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));

if ($token === '') {
    json_response([
        'status' => 'error',
        'message' => 'token is required'
    ], 422);
}

$stmt = db()->prepare("
    SELECT
        at.device_id,
        d.device_uuid,
        d.hostname
    FROM agent_tokens at
    INNER JOIN devices d ON d.id = at.device_id
    WHERE at.token = :token
      AND at.expires_at > NOW()
    LIMIT 1
");
$stmt->execute(['token' => $token]);
$row = $stmt->fetch();

if (!$row) {
    json_response([
        'status' => 'error',
        'message' => 'Invalid agent token'
    ], 401);
}

db()->prepare("
    UPDATE devices
    SET status = 'online',
        ip_address = :ip_address,
        last_seen_at = NOW(),
        updated_at = NOW()
    WHERE id = :device_id
")->execute([
    'ip_address' => $ipAddress,
    'device_id' => $row['device_id']
]);

db()->prepare("
    INSERT INTO agent_heartbeats (
        device_id,
        heartbeat_at,
        ip_address,
        created_at
    ) VALUES (
        :device_id,
        NOW(),
        :ip_address,
        NOW()
    )
")->execute([
    'device_id' => $row['device_id'],
    'ip_address' => $ipAddress
]);

$stmt = db()->prepare("
    SELECT
        p.id,
        p.name,
        p.description
    FROM device_policy_assignments dpa
    INNER JOIN policies p ON p.id = dpa.policy_id
    WHERE dpa.device_id = :device_id
    LIMIT 1
");
$stmt->execute(['device_id' => $row['device_id']]);
$policy = $stmt->fetch();

$rules = [];

if ($policy) {
    $ruleStmt = db()->prepare("
        SELECT
            id,
            rule_type,
            match_type,
            value,
            enabled
        FROM policy_rules
        WHERE policy_id = :policy_id
        ORDER BY id DESC
    ");
    $ruleStmt->execute(['policy_id' => $policy['id']]);
    $rules = $ruleStmt->fetchAll();
}

json_response([
    'status' => 'success',
    'message' => 'Heartbeat received',
    'data' => [
        'device_uuid' => $row['device_uuid'],
        'hostname' => $row['hostname'],
        'policy' => $policy ?: null,
        'rules' => $rules
    ]
]);