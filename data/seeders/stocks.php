<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

return [
    'name' => 'stocks',
    'run' => static function (): void {
        $inputFile = dirname(__DIR__) . '/raw/sp500-members.json';

        if (!is_file($inputFile)) {
            throw new RuntimeException("Missing raw data file: {$inputFile}");
        }

        $raw = file_get_contents($inputFile);
        if ($raw === false) {
            throw new RuntimeException("Unable to read raw data file: {$inputFile}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raw data file does not contain valid JSON.');
        }

        $symbols = [];

        foreach ($decoded as $key => $value) {
            if ($key === '_meta' || !is_array($value)) {
                continue;
            }

            foreach ($value as $ticker) {
                $symbol = normalize_stock_symbol((string) $ticker);
                if ($symbol === '') {
                    continue;
                }

                $canonicalSymbol = resolve_price_symbol($symbol);
                if ($canonicalSymbol === '') {
                    continue;
                }

                $symbols[$canonicalSymbol] = $canonicalSymbol;
            }
        }

        $pdo = connect_pdo();
        $existingSymbols = fetch_existing_symbols($pdo);
        $missingSymbols = array_diff_key($symbols, array_flip($existingSymbols));

        $insertStmt = $pdo->prepare('INSERT INTO stocks (symbol) VALUES (:symbol)');

        $inserted = 0;
        foreach ($missingSymbols as $symbol => $ignored) {
            $insertStmt->execute([
                'symbol' => $symbol,
            ]);
            $inserted += $insertStmt->rowCount();
        }

        echo "Processed " . count($symbols) . " symbols.\n";
        echo "Inserted {$inserted} new stock rows.\n";
        echo "Skipped " . (count($symbols) - $inserted) . " existing stock rows.\n";
    },
];

function fetch_existing_symbols(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT symbol FROM stocks');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map(static fn (mixed $symbol): string => (string) $symbol, $rows);
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
