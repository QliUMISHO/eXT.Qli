<?php
declare(strict_types=1);

$stmt = db()->query("
    SELECT
        p.id,
        p.name,
        p.description,
        p.status,
        p.created_at,
        COUNT(pr.id) AS rule_count
    FROM policies p
    LEFT JOIN policy_rules pr ON pr.policy_id = p.id
    GROUP BY p.id
    ORDER BY p.id DESC
");

json_response([
    'status' => 'success',
    'data' => $stmt->fetchAll()
]);