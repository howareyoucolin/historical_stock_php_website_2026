<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = connect_pdo();
    ensure_migrations_table($pdo);

    $migrateDir = __DIR__ . '/migrates';
    $files = glob($migrateDir . '/*.php');
    sort($files);

    $applied = applied_migrations($pdo);
    $ran = [];

    foreach ($files as $file) {
        $migration = require $file;

        if (!is_array($migration) || !isset($migration['name'], $migration['up'])) {
            throw new RuntimeException("Invalid migration file: {$file}");
        }

        $name = (string) $migration['name'];
        if (isset($applied[$name])) {
            continue;
        }

        try {
            $pdo->exec((string) $migration['up']);

            $stmt = $pdo->prepare(
                'INSERT INTO migrations (migration_name, applied_at) VALUES (:migration_name, NOW())'
            );
            $stmt->execute(['migration_name' => $name]);

            $ran[] = $name;
        } catch (Throwable $e) {
            throw $e;
        }
    }

    if ($ran === []) {
        echo "No pending migrations.\n";
        exit;
    }

    echo "Applied migrations:\n";
    foreach ($ran as $name) {
        echo "- {$name}\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
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

    if ($database === '') {
        throw new RuntimeException('Set DB_DATABASE or DB_DSN before running migrations.');
    }

    $mysqlDsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return new PDO($mysqlDsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL
    );
}

function applied_migrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT migration_name FROM migrations');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_fill_keys($rows, true);
}
