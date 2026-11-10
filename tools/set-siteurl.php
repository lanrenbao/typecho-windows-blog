<?php
/**
 * Manually set the Typecho siteUrl.
 * Usage: php set-siteurl.php http://192.168.1.x:8080
 */
$root = dirname(__DIR__) . '/www';
$dbFile = $root . '/usr/typecho.db';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php set-siteurl.php <http://your-address:8080>\n");
    exit(1);
}

$url = rtrim($argv[1], '/');
if (!preg_match('#^https?://#i', $url)) {
    fwrite(STDERR, "URL must start with http:// or https://\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("UPDATE typecho_options SET value = ? WHERE name = 'siteUrl' AND user = 0");
$stmt->execute([$url]);

echo "siteUrl updated to: {$url}\n";
echo "affected rows: " . $stmt->rowCount() . "\n";
