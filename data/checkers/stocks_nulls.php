<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = connect_pdo();

    $stmt = $pdo->query(
        <<<'SQL'
SELECT
    id,
    symbol,
    company_name,
    sector,
    industry,
    description
FROM stocks
WHERE company_name IS NULL
   OR sector IS NULL
   OR industry IS NULL
   OR description IS NULL
ORDER BY symbol
SQL
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        echo "No stocks rows have NULL values in company_name, sector, industry, or description.\n";
        exit(0);
    }

    echo 'Found ' . count($rows) . " stocks rows with NULL values.\n\n";

    foreach ($rows as $row) {
        $missing = [];

        foreach (['company_name', 'sector', 'industry', 'description'] as $column) {
            if ($row[$column] === null) {
                $missing[] = $column;
            }
        }

        echo sprintf(
            "#%d %s | missing: %s\n",
            (int) $row['id'],
            (string) $row['symbol'],
            implode(', ', $missing)
        );
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Checker failed: ' . $e->getMessage() . "\n";
    exit(1);
}

function connect_pdo(): PDO
{
    $dsn = getenv('DB_DSN');
    if ($dsn !== false && $dsn !== '') {
        $username = getenv('DB_USERNAME') ?: null;
        $password = getenv('DB_PASSWORD') ?: null;

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_DATABASE') ?: '';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    if ($database !== '') {
        $mysqlDsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new PDO($mysqlDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    $configFile = dirname(__DIR__, 2) . '/public/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Missing config.php.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.');
    }

    $db = is_array($config['db'] ?? null) ? $config['db'] : [];
    $configHost = (string) ($db['host'] ?? '');
    $configDatabase = (string) ($db['database'] ?? '');
    $configUsername = (string) ($db['username'] ?? '');
    $configPassword = (string) ($db['password'] ?? '');
    $configCharset = (string) ($db['charset'] ?? 'utf8mb4');
    $configPort = (string) ($db['port'] ?? '');

    if ($configHost === '' || $configDatabase === '' || $configUsername === '') {
        throw new RuntimeException('Database config is incomplete.');
    }

    $mysqlDsn = "mysql:host={$configHost};dbname={$configDatabase};charset={$configCharset}";
    if ($configPort !== '') {
        $mysqlDsn = "mysql:host={$configHost};port={$configPort};dbname={$configDatabase};charset={$configCharset}";
    }

    return new PDO($mysqlDsn, $configUsername, $configPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
