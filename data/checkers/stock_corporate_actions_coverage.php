<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

try {
    $actionsPath = dirname(__DIR__) . '/config/stock-corporate-actions.json';
    $actionSymbols = load_action_symbols($actionsPath);

    $pdo = connect_pdo();
    $stmt = $pdo->query(
        <<<'SQL'
SELECT DISTINCT s.symbol
FROM stocks s
LEFT JOIN stock_daily_prices p ON p.stock_id = s.id
WHERE p.id IS NULL
ORDER BY s.symbol
SQL
    );

    $missingSymbols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($missingSymbols)) {
        throw new RuntimeException('Unable to read missing stock symbols.');
    }

    $covered = [];
    $uncovered = [];

    foreach ($missingSymbols as $symbolValue) {
        $symbol = trim((string) $symbolValue);
        if ($symbol === '') {
            continue;
        }

        if (isset($actionSymbols[$symbol])) {
            $covered[] = $symbol;
        } else {
            $uncovered[] = $symbol;
        }
    }

    echo 'Missing daily-price symbols: ' . count($missingSymbols) . "\n";
    echo 'Covered by stock-corporate-actions.json: ' . count($covered) . "\n";
    echo 'Still uncovered: ' . count($uncovered) . "\n";

    if ($covered !== []) {
        echo "\nCovered symbols\n";
        foreach ($covered as $symbol) {
            echo $symbol . "\n";
        }
    }

    if ($uncovered !== []) {
        echo "\nFirst 50 uncovered symbols\n";
        foreach (array_slice($uncovered, 0, 50) as $symbol) {
            echo $symbol . "\n";
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Checker failed: ' . $e->getMessage() . "\n";
    exit(1);
}

function load_action_symbols(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Missing config file: {$path}");
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Unable to read config file: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Corporate actions config is not valid JSON.');
    }

    $actions = $decoded['actions'] ?? null;
    if (!is_array($actions)) {
        throw new RuntimeException('Corporate actions config must contain an actions array.');
    }

    $symbols = [];

    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }

        $symbol = strtoupper(trim((string) ($action['stockCode'] ?? '')));
        if ($symbol === '') {
            continue;
        }

        $symbols[$symbol] = true;
    }

    return $symbols;
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
