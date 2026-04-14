<?php
class NetworkScanner
{
    public static function scan(string $input): array
    {
        $resolvedSubnet = self::resolveScanTarget($input);

        if (!self::commandExists('nmap')) {
            throw new RuntimeException('nmap is not installed on this Ubuntu server.');
        }

        return self::scanWithNmap($resolvedSubnet);
    }

    public static function resolveScanTarget(string $input): string
    {
        $input = trim($input);

        if ($input === '') {
            throw new RuntimeException('Subnet or IP is required.');
        }

        if (strpos($input, '/') !== false) {
            return self::normalizeCidr($input);
        }

        if (!filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Invalid subnet or IP format.');
        }

        $interfaces = self::getLocalInterfaces();

        foreach ($interfaces as $iface) {
            $ifaceSubnet = self::buildSubnetFromIpAndPrefix($iface['ip'], $iface['prefix']);

            if ($input === $iface['ip']) {
                return $ifaceSubnet;
            }

            if (self::cidrContainsIp($ifaceSubnet, $input)) {
                return $ifaceSubnet;
            }
        }

        return self::networkAddress($input, 24) . '/24';
    }

    public static function detectBestSubnetForServer(): ?array
    {
        $interfaces = self::getLocalInterfaces();
        if (empty($interfaces)) {
            return null;
        }

        $defaultIf = self::getDefaultRouteInterface();
        $scored = [];

        foreach ($interfaces as $iface) {
            $score = 0;
            $name = $iface['name'];
            $ip = $iface['ip'];
            $prefix = (int)$iface['prefix'];

            if ($name === 'lo') {
                $score -= 1000;
            }

            if (preg_match('/^(docker|br-|veth|virbr|vmnet)/i', $name)) {
                $score -= 80;
            }

            if (preg_match('/^(tun|tap|wg|zt|tailscale)/i', $name)) {
                $score -= 20;
            }

            if (self::isPrivateIPv4($ip)) {
                $score += 100;
            } else {
                $score += 10;
            }

            if ($defaultIf && $defaultIf === $name) {
                $score += 30;
            }

            $scored[] = [
                'name' => $name,
                'ip' => $ip,
                'prefix' => $prefix,
                'subnet' => self::buildSubnetFromIpAndPrefix($ip, $prefix),
                'score' => $score,
            ];
        }

        usort($scored, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $scored[0] ?? null;
    }

    public static function getLocalInterfaces(): array
    {
        if (!self::commandExists('ip')) {
            return [];
        }

        $ipPath = self::findCommandPath('ip');
        $result = self::runCommand($ipPath . ' -4 -o addr show up scope global 2>&1');

        if ($result['exit_code'] !== 0 || trim($result['output']) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result['output']));
        $interfaces = [];

        foreach ($lines as $line) {
            if (preg_match('/^\d+:\s+([^\s]+)\s+inet\s+([0-9.]+)\/(\d+)/', $line, $matches)) {
                $interfaces[] = [
                    'name' => $matches[1],
                    'ip' => $matches[2],
                    'prefix' => (int)$matches[3],
                ];
            }
        }

        return $interfaces;
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
        $stmt = $pdo->prepare("UPDATE devices SET status = 'Offline' WHERE ip_address NOT IN ($placeholders)");
        $stmt->execute($liveIps);
    }

    private static function scanWithNmap(string $subnet): array
    {
        $nmapPath = self::findCommandPath('nmap');

        $command = $nmapPath . ' -sn -n --max-retries 1 --host-timeout 2s ' . escapeshellarg($subnet) . ' -oG - 2>&1';
        $result = self::runCommand($command);

        if ($result['exit_code'] !== 0 && trim($result['output']) === '') {
            throw new RuntimeException('Failed to run nmap.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $result['output']);
        $devices = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                preg_match('/^Host:\s+([0-9.]+)\s+\((.*?)\)\s+Status:\s+Up/i', $line, $matches) ||
                preg_match('/^Host:\s+([0-9.]+)\s+Status:\s+Up/i', $line, $matches)
            ) {
                $ip = $matches[1];
                $hostname = '';

                if (isset($matches[2]) && trim($matches[2]) !== '') {
                    $hostname = trim($matches[2]);
                } else {
                    $reverse = @gethostbyaddr($ip);
                    $hostname = ($reverse && $reverse !== $ip) ? $reverse : '';
                }

                $devices[$ip] = [
                    'ip_address' => $ip,
                    'hostname' => $hostname,
                    'mac_address' => '',
                    'vendor' => '',
                    'status' => 'Online',
                ];

                if (preg_match('/MAC Address:\s*([0-9A-F:]{17})(?:\s+\((.*?)\))?/i', $line, $macMatches)) {
                    $devices[$ip]['mac_address'] = strtoupper($macMatches[1]);
                    $devices[$ip]['vendor'] = trim($macMatches[2] ?? '');
                }
            }
        }

        return [
            'subnet' => $subnet,
            'devices' => array_values($devices),
            'method' => 'nmap',
        ];
    }

    private static function normalizeCidr(string $cidr): string
    {
        if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) {
            throw new RuntimeException('Invalid CIDR format. Example: 172.31.191.0/24');
        }

        [$ip, $prefix] = explode('/', $cidr, 2);
        $prefix = (int)$prefix;

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Invalid IPv4 address in CIDR.');
        }

        if ($prefix < 1 || $prefix > 32) {
            throw new RuntimeException('Invalid CIDR prefix.');
        }

        return self::networkAddress($ip, $prefix) . '/' . $prefix;
    }

    private static function getDefaultRouteInterface(): ?string
    {
        if (!self::commandExists('ip')) {
            return null;
        }

        $ipPath = self::findCommandPath('ip');
        $result = self::runCommand($ipPath . ' route show default 2>&1');

        if ($result['exit_code'] !== 0 || trim($result['output']) === '') {
            return null;
        }

        if (preg_match('/\bdev\s+([^\s]+)/', $result['output'], $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private static function cidrContainsIp(string $cidr, string $ip): bool
    {
        [$subnetIp, $prefix] = explode('/', $cidr, 2);
        $prefix = (int)$prefix;

        $mask = self::maskFromPrefix($prefix);
        $subnetLong = self::ipToUnsignedLong($subnetIp);
        $ipLong = self::ipToUnsignedLong($ip);

        return (($subnetLong & $mask) === ($ipLong & $mask));
    }

    private static function buildSubnetFromIpAndPrefix(string $ip, int $prefix): string
    {
        return self::networkAddress($ip, $prefix) . '/' . $prefix;
    }

    private static function networkAddress(string $ip, int $prefix): string
    {
        $mask = self::maskFromPrefix($prefix);
        $ipLong = self::ipToUnsignedLong($ip);
        $networkLong = $ipLong & $mask;
        return long2ip($networkLong);
    }

    private static function maskFromPrefix(int $prefix): int
    {
        if ($prefix <= 0) {
            return 0;
        }

        if ($prefix >= 32) {
            return 0xFFFFFFFF;
        }

        return (int)((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    }

    private static function ipToUnsignedLong(string $ip): int
    {
        $long = ip2long($ip);

        if ($long === false) {
            throw new RuntimeException('Invalid IP address.');
        }

        return (int)sprintf('%u', $long);
    }

    private static function isPrivateIPv4(string $ip): bool
    {
        $long = self::ipToUnsignedLong($ip);

        $ranges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= self::ipToUnsignedLong($start) && $long <= self::ipToUnsignedLong($end)) {
                return true;
            }
        }

        return false;
    }

    private static function commandExists(string $command): bool
    {
        try {
            return self::findCommandPath($command) !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function findCommandPath(string $command): string
    {
        $result = self::runCommand('command -v ' . escapeshellarg($command) . ' 2>&1');
        $path = trim($result['output']);

        if ($result['exit_code'] !== 0 || $path === '') {
            throw new RuntimeException($command . ' is not available on this Ubuntu server.');
        }

        return $path;
    }

    private static function runCommand(string $command): array
    {
        if (!self::isFunctionDisabled('exec')) {
            $output = [];
            $exitCode = 0;
            @exec($command, $output, $exitCode);

            return [
                'output' => implode("\n", $output),
                'exit_code' => (int)$exitCode,
            ];
        }

        if (!self::isFunctionDisabled('shell_exec')) {
            $output = @shell_exec($command);
            return [
                'output' => (string)$output,
                'exit_code' => trim((string)$output) === '' ? 1 : 0,
            ];
        }

        throw new RuntimeException('PHP exec() and shell_exec() are disabled.');
    }

    private static function isFunctionDisabled(string $function): bool
    {
        $disabled = ini_get('disable_functions');
        if (!$disabled) {
            return false;
        }

        $disabledList = array_map('trim', explode(',', $disabled));
        return in_array($function, $disabledList, true);
    }
}