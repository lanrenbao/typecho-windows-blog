<?php
/**
 * Typecho siteUrl auto-sync (multi-strategy)
 *
 * Priority:
 *   0) Cloudflare tunnel domain (named tunnel: tools/tunnel-host.txt)
 *   1) Cloudflare Quick Tunnel random URL (parsed from cloudflared logs)
 *   2) Real public IPv4 that actually sits on a local NIC
 *   3) Stable global IPv6 (CGNAT fallback, temporary addresses excluded)
 *
 * Run by scheduled task every 10 minutes, or manually with --print (dry run).
 */

$root    = dirname(__DIR__) . '/www';
$dbFile  = $root . '/usr/typecho.db';
$logFile = dirname(__DIR__) . '/logs/siteurl-sync.log';
$port    = '8080';
$dryRun  = in_array('--print', $argv, true);

function logline(string $msg, string $file): void
{
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

/**
 * Local unicast addresses.
 * NOTE: on Windows, net_get_interfaces() only lists virtual adapters, and
 * spawning PowerShell from PHP gets killed by some security policies, so we
 * parse `ipconfig` output instead (address parts are pure ASCII).
 *
 * @return array{0: string[], 1: string[]} [ipv4, stable ipv6 (no temporary)]
 */
function localAddrs(): array
{
    $v4 = [];
    $v6 = [];
    $raw = (string) @shell_exec('ipconfig');

    foreach (preg_split('/\r?\n/', $raw) as $line) {
        // split on FIRST colon: labels contain one colon, IPv6 addresses many
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $label = substr($line, 0, $pos);
        $val   = trim(substr($line, $pos + 1));
        $val   = preg_replace('/%.*$/', '', $val);   // strip zone id, e.g. fe80::1%12

        $isV6 = stripos($label, 'ipv6') !== false;
        $isV4 = stripos($label, 'ipv4') !== false;

        if ($isV6 && filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (stripos($val, 'fe80') === 0 || $val === '::1') {
                continue;
            }
            // skip temporary/privacy addresses ("Temporary IPv6 Address",
            // Chinese GBK bytes for "临时" included)
            if (stripos($label, 'temporary') !== false || strpos($label, "\xC1\xD9\xCA\xB1") !== false) {
                continue;
            }
            if (!in_array($val, $v6, true)) {
                $v6[] = $val;
            }
        } elseif ($isV4 && filter_var($val, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (strpos($val, '127.') === 0 || strpos($val, '169.254.') === 0) {
                continue;
            }
            if (!in_array($val, $v4, true)) {
                $v4[] = $val;
            }
        }
    }

    return [$v4, $v6];
}

/** Outbound public IPv4 (multiple sources, first hit wins) */
function publicIPv4(): ?string
{
    $sources = [
        'https://ipv4.icanhazip.com',
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
    ];
    foreach ($sources as $src) {
        $ch = curl_init($src);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $out = trim((string) curl_exec($ch));
        curl_close($ch);
        if (filter_var($out, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $out;
        }
    }
    return null;
}

/**
 * Current public tunnel URL.
 *   1) named tunnel: fixed domain from tools/tunnel-host.txt
 *   2) quick tunnel: last *.trycloudflare.com found in cloudflared logs
 *      (only trusted while a cloudflared process is alive)
 */
function tunnelUrl(): ?string
{
    $base = dirname(__DIR__);

    // named tunnel: fixed domain wins
    $hostFile = $base . '/tools/tunnel-host.txt';
    if (is_file($hostFile)) {
        $host = trim((string) @file_get_contents($hostFile));
        if ($host !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host)) {
            return 'https://' . $host;
        }
    }

    // don't trust stale log URLs when the process is gone
    $procs = (string) @shell_exec('tasklist /FI "IMAGENAME eq cloudflared.exe" /NH');
    if (stripos($procs, 'cloudflared') === false) {
        return null;
    }

    $pm2Logs = getenv('PM2_HOME') ?: (getenv('USERPROFILE') ?: 'C:/Users/Public') . '/.pm2/logs';
    $logs = [
        $base . '/logs/cf-tunnel.log',
        $base . '/logs/cf-tunnel-out.log',
        $pm2Logs . '/cf-tunnel-error.log',
        $pm2Logs . '/cf-tunnel-out.log',
    ];

    $latest     = null;
    $latestTime = 0;
    foreach ($logs as $f) {
        if (!is_file($f)) {
            continue;
        }
        $mtime   = @filemtime($f) ?: 0;
        $content = @file_get_contents($f);
        if ($content === false || $content === '') {
            continue;
        }
        if (preg_match_all('#https://[a-z0-9][a-z0-9-]*\.trycloudflare\.com#i', $content, $m)) {
            if ($mtime >= $latestTime) {
                $latest     = end($m[0]);
                $latestTime = $mtime;
            }
        }
    }

    return $latest;
}

/** Private/reserved IPv4 check (RFC1918, CGNAT 100.64/10, loopback, link-local) */
function isPrivateIPv4(string $ip): bool
{
    $long = ip2long($ip);
    if ($long === false) {
        return true;
    }
    $ranges = [
        ['10.0.0.0',     '10.255.255.255'],
        ['172.16.0.0',   '172.31.255.255'],
        ['192.168.0.0',  '192.168.255.255'],
        ['100.64.0.0',   '100.127.255.255'],  // CGNAT
        ['127.0.0.0',    '127.255.255.255'],
        ['169.254.0.0',  '169.254.255.255'],
    ];
    foreach ($ranges as [$a, $b]) {
        if ($long >= ip2long($a) && $long <= ip2long($b)) {
            return true;
        }
    }
    return false;
}

[$locals4, $locals6] = localAddrs();
$pub4   = publicIPv4();
$chosen = null;
$reason = '';

// 0) tunnel first: once cloudflared is up, the public entry is an https domain
$tunnelUrl = tunnelUrl();

if ($tunnelUrl !== null) {
    $chosen = $tunnelUrl;
    $reason = 'Cloudflare tunnel';
} elseif ($pub4 !== null && in_array($pub4, $locals4, true) && !isPrivateIPv4($pub4)) {
    // 1) public IPv4 must actually be bound to a local NIC to count
    $chosen = 'http://' . $pub4 . ':' . $port;
    $reason = 'public IPv4 direct';
} elseif ($pub4 !== null && !empty($locals6)) {
    // 2) CGNAT: fall back to IPv6
    $chosen = 'http://[' . $locals6[0] . ']:' . $port;
    $reason = "CGNAT (egress {$pub4} not local) -> IPv6";
} elseif (!empty($locals6)) {
    $chosen = 'http://[' . $locals6[0] . ']:' . $port;
    $reason = 'IPv4 unusable -> IPv6';
}

if ($chosen === null) {
    logline('no usable address, keeping current', $logFile);
    fwrite(STDERR, "no usable address\n");
    exit(1);
}

// persist current URL for quick inspection
@file_put_contents(dirname(__DIR__) . '/tools/current-url.txt', $chosen . PHP_EOL);

if ($dryRun) {
    echo "chosen = {$chosen}\n";
    echo "reason = {$reason}\n";
    echo "local_v4 = " . implode(', ', $locals4) . "\n";
    echo "local_v6 = " . implode(', ', $locals6) . "\n";
    exit(0);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $cur = $pdo->query("SELECT value FROM typecho_options WHERE name = 'siteUrl' AND user = 0")->fetchColumn();

    if ($cur === $chosen) {
        logline("no change: {$chosen}", $logFile);
        exit(0);
    }

    $stmt = $pdo->prepare("UPDATE typecho_options SET value = ? WHERE name = 'siteUrl' AND user = 0");
    $stmt->execute([$chosen]);
    logline("updated: {$cur} -> {$chosen} ({$reason})", $logFile);
} catch (Throwable $e) {
    logline('DB error: ' . $e->getMessage(), $logFile);
    exit(1);
}
