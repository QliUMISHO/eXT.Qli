<?php
declare(strict_types=1);

$data = input_json();

$deviceUuid = trim((string)($data['device_uuid'] ?? ''));
$hostname = trim((string)($data['hostname'] ?? ''));
$ipAddress = trim((string)($data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
$operatingSystem = trim((string)($data['operating_system'] ?? ''));
$agentVersion = trim((string)($data['agent_version'] ?? '1.0.0'));

if ($deviceUuid === '' || $hostname === '') {
    json_response([
        'status' => 'error',
        'message' => 'device_uuid and hostname are required'
    ], 422);
}

$stmt = db()->prepare("SELECT id FROM devices WHERE device_uuid = :device_uuid LIMIT 1");
$stmt->execute(['device_uuid' => $deviceUuid]);
$existing = $stmt->fetch();

if ($existing) {
    $update = db()->prepare("
        UPDATE devices
        SET hostname = :hostname,
            ip_address = :ip_address,
            operating_system = :operating_system,
            agent_version = :agent_version,
            status = 'online',
            last_seen_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");

    $update->execute([
        'hostname' => $hostname,
        'ip_address' => $ipAddress,
        'operating_system' => $operatingSystem,
        'agent_version' => $agentVersion,
        'id' => $existing['id']
    ]);

    $deviceId = (int)$existing['id'];
} else {
    $insert = db()->prepare("
        INSERT INTO devices (
            device_uuid,
            hostname,
            ip_address,
            operating_system,
            agent_version,
            status,
            created_at,
            updated_at,
            last_seen_at
        ) VALUES (
            :device_uuid,
            :hostname,
            :ip_address,
            :operating_system,
            :agent_version,
            'online',
            NOW(),
            NOW(),
            NOW()
        )
    ");

    $insert->execute([
        'device_uuid' => $deviceUuid,
        'hostname' => $hostname,
        'ip_address' => $ipAddress,
        'operating_system' => $operatingSystem,
        'agent_version' => $agentVersion
    ]);

    $deviceId = (int)db()->lastInsertId();
}

$tokenValue = hash('sha256', $deviceUuid . '|' . APP_KEY . '|' . time());

db()->prepare("DELETE FROM agent_tokens WHERE device_id = :device_id")->execute([
    'device_id' => $deviceId
]);

db()->prepare("
    INSERT INTO agent_tokens (
        device_id,
        token,
        created_at,
        expires_at
    ) VALUES (
        :device_id,
        :token,
        NOW(),
        DATE_ADD(NOW(), INTERVAL 30 DAY)
    )
")->execute([
    'device_id' => $deviceId,
    'token' => $tokenValue
]);

app_log('agent', 'Enroll success', [
    'device_uuid' => $deviceUuid,
    'hostname' => $hostname
]);

json_response([
    'status' => 'success',
    'message' => 'Device enrolled',
    'data' => [
        'token' => $tokenValue,
        'device_id' => $deviceId,
        'sync_interval_seconds' => 60
    ]
]);