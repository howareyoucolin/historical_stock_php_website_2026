<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$db = $config['db'] ?? [];

$host = (string) ($db['host'] ?? '');
$database = (string) ($db['database'] ?? '');
$username = (string) ($db['username'] ?? '');
$password = (string) ($db['password'] ?? '');
$charset = (string) ($db['charset'] ?? 'utf8mb4');

header('Content-Type: text/plain; charset=utf-8');

if ($host === '' || $database === '' || $username === '') {
    http_response_code(500);
    echo "Database config is incomplete.\n";
    exit;
}

$dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

try {
    new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    echo "Database connection is working.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Database connection failed: {$e->getMessage()}\n";
}
