<?php
declare(strict_types=1);

$data = input_json();

$id = (int)($data['id'] ?? 0);
$policyId = (int)($data['policy_id'] ?? 0);
$ruleType = trim((string)($data['rule_type'] ?? 'block'));
$matchType = trim((string)($data['match_type'] ?? 'exact'));
$value = strtolower(trim((string)($data['value'] ?? '')));
$enabled = (int)($data['enabled'] ?? 1);

if ($policyId <= 0 || $value === '') {
    json_response([
        'status' => 'error',
        'message' => 'policy_id and value are required'
    ], 422);
}

if ($id > 0) {
    $stmt = db()->prepare("
        UPDATE policy_rules
        SET rule_type = :rule_type,
            match_type = :match_type,
            value = :value,
            enabled = :enabled
        WHERE id = :id
    ");

    $stmt->execute([
        'rule_type' => $ruleType,
        'match_type' => $matchType,
        'value' => $value,
        'enabled' => $enabled,
        'id' => $id
    ]);
} else {
    $stmt = db()->prepare("
        INSERT INTO policy_rules (
            policy_id,
            rule_type,
            match_type,
            value,
            enabled,
            created_at
        ) VALUES (
            :policy_id,
            :rule_type,
            :match_type,
            :value,
            :enabled,
            NOW()
        )
    ");

    $stmt->execute([
        'policy_id' => $policyId,
        'rule_type' => $ruleType,
        'match_type' => $matchType,
        'value' => $value,
        'enabled' => $enabled
    ]);

    $id = (int)db()->lastInsertId();
}

json_response([
    'status' => 'success',
    'message' => 'Rule saved',
    'data' => [
        'id' => $id
    ]
]);