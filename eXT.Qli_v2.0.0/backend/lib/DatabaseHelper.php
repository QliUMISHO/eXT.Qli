<?php

class DatabaseHelper
{
    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.COLUMNS\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n              AND COLUMN_NAME = :column_name\n        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
        return $cache[$key];
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n            FROM INFORMATION_SCHEMA.TABLES\n            WHERE TABLE_SCHEMA = DATABASE()\n              AND TABLE_NAME = :table_name\n        ");
        $stmt->execute([':table_name' => $table]);

        $cache[$table] = ((int)$stmt->fetchColumn() > 0);
        return $cache[$table];
    }

    public static function cleanUsername($value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        if (in_array($lower, [
            'unknown',
            'none',
            'null',
            '-',
            'username unavailable',
            'fetching username...',
            'waiting for username...',
            'username not reported',
        ], true)) {
            return '';
        }

        return $value;
    }

    public static function extractUsernameFromArray(array $payload): string
    {
        $candidateKeys = [
            'endpoint_username_output',
            'username_stdout',
            'username_probe_output',
            'logged_in_username',
            'username',
            'current_user',
            'logged_in_user',
            'active_user',
            'console_user',
            'user',
            'display_name',
        ];

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $username = self::cleanUsername($payload[$key]);
                if ($username !== '') {
                    return $username;
                }
            }
        }

        return '';
    }

    public static function extractUsernameFromPayload(?string $payloadJson): string
    {
        if (!$payloadJson) {
            return '';
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return '';
        }

        return self::extractUsernameFromArray($payload);
    }
}
