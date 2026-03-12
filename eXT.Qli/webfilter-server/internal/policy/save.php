<?php
declare(strict_types=1);

$data = input_json();

$id = (int)($data['id'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
$description = trim((string)($data['description'] ?? ''));
$status = trim((string)($data['status'] ?? 'active'));

if ($name === '') {
    json_response([
        'status' => 'error',
        'message' => 'Policy name is required'
    ], 422);
}

if ($id > 0) {
    $stmt = db()->prepare("
        UPDATE policies
        SET name = :name,
            description = :description,
            status = :status,
            updated_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        'name' => $name,
        'description' => $description,
        'status' => $status,
        'id' => $id
    ]);
} else {
    $stmt = db()->prepare("
        INSERT INTO policies (
            name,
            description,
            status,
            created_at,
            updated_at
        ) VALUES (
            :name,
            :description,
            :status,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        'name' => $name,
        'description' => $description,
        'status' => $status
    ]);

    $id = (int)db()->lastInsertId();
}

json_response([
    'status' => 'success',
    'message' => 'Policy saved',
    'data' => [
        'id' => $id
    ]
]);