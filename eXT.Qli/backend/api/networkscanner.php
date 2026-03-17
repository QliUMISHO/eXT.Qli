<?php
class NetworkScanner
{
    public static function validateSubnet(string $subnet): bool
    {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $subnet)) {
            return false;
        }

        [$ip, $cidr] = explode('/', $subnet, 2);
        $parts = explode('.', $ip);

        foreach ($parts as $part) {
            if ((int)$part < 0 || (int)$part > 255) {
                return false;
            }
        }

        if ((int)$cidr < 0 || (int)$cidr > 32) {
            return false;
        }

        if (ALLOW_ONLY_PRIVATE_SUBNETS && !self::isPrivateIPv4($ip)) {
            return false;
        }

        return true;
    }

    public static function isPrivateIPv4(string $ip): bool
    {
        $long = ip2long($ip);

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    public static function commandExists(string $command): bool
    {
        $result = trim((string)shell_exec("command -v " . escapeshellarg($command) . " 2>/dev/null"));
        return $result !== '';
    }

    public static function scan(string $subnet): array
    {
        if (!self::validateSubnet($subnet)) {
            throw new RuntimeException('Invalid or non-private subnet.');
        }

        if (self::commandExists('nmap')) {
            return self::scanWithNmap($subnet);
        }

        throw new RuntimeException('nmap is not installed on this Ubuntu server.');
    }

    private static function scanWithNmap(string $subnet): array
    {
        $command = 'nmap -sn ' . escapeshellarg($subnet) . ' -oG - 2>&1';
        $output = shell_exec($command);

        if ($output === null) {
            throw new RuntimeException('Failed to run nmap.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $output);
        $devices = [];
        $currentIp = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/^Host:\s+([0-9.]+)\s+\((.*?)\)\s+Status:\s+Up$/', $line, $matches)) {
                $currentIp = $matches[1];
                $hostname = trim($matches[2]) !== '' ? trim($matches[2]) : gethostbyaddr($currentIp);

                if ($hostname === $currentIp) {
                    $hostname = '';
                }

                $devices[$currentIp] = [
                    'ip_address' => $currentIp,
                    'hostname' => $hostname,
                    'mac_address' => '',
                    'vendor' => '',
                    'status' => 'Online',
                ];
                continue;
            }

            if ($currentIp && preg_match('/MAC Address:\s+([0-9A-F:]+)\s+\((.*?)\)$/i', $line, $matches)) {
                $devices[$currentIp]['mac_address'] = strtoupper($matches[1]);
                $devices[$currentIp]['vendor'] = trim($matches[2]);
            }
        }

        return array_values($devices);
    }

    public static function saveScanResults(PDO $pdo, array $results): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO devices (
                ip_address,
                hostname,
                mac_address,
                vendor,
                status,
                first_seen,
                last_seen
            ) VALUES (
                :ip_address,
                :hostname,
                :mac_address,
                :vendor,
                :status,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                mac_address = VALUES(mac_address),
                vendor = VALUES(vendor),
                status = VALUES(status),
                last_seen = NOW()
        ");

        foreach ($results as $row) {
            $stmt->execute([
                ':ip_address' => $row['ip_address'] ?? '',
                ':hostname' => $row['hostname'] ?? '',
                ':mac_address' => $row['mac_address'] ?? '',
                ':vendor' => $row['vendor'] ?? '',
                ':status' => $row['status'] ?? 'Online',
            ]);
        }

        self::markMissingHostsOffline($pdo, $results);
    }

    private static function markMissingHostsOffline(PDO $pdo, array $results): void
    {
        $liveIps = array_column($results, 'ip_address');

        if (empty($liveIps)) {
            $pdo->exec("UPDATE devices SET status = 'Offline'");
            return;
        }

        $placeholders = implode(',', array_fill(0, count($liveIps), '?'));
        $sql = "UPDATE devices SET status = 'Offline' WHERE ip_address NOT IN ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($liveIps);
    }
}