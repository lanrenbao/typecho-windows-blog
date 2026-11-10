<?php
/**
 * Site health check: if the homepage is not 200/302, restart the whole stack.
 * Invoked by scheduled task every 5 minutes.
 */
$logFile = dirname(__DIR__) . '/logs/healthcheck.log';
$url     = 'http://127.0.0.1:8080/';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_NOBODY         => false,
]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200 || $code === 302) {
    exit(0);
}

file_put_contents(
    $logFile,
    '[' . date('Y-m-d H:i:s') . "] health check failed (HTTP {$code}), restarting stack" . PHP_EOL,
    FILE_APPEND
);

exec('cmd /c "' . dirname(__DIR__) . '\\start-server.cmd"', $out, $rc);

file_put_contents(
    $logFile,
    '[' . date('Y-m-d H:i:s') . "] restart finished, exit code {$rc}" . PHP_EOL,
    FILE_APPEND
);

exit($rc === 0 ? 0 : 1);
