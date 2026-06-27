<?php

declare(strict_types=1);

/**
 * Integrity checker for stocks / stock_daily_prices.
 *
 * Verifies two things:
 *   1. No duplicate price rows for the same (stock_id, trade_date).
 *   2. No gap longer than N days (default 7) between consecutive trade dates
 *      for a stock. A gap wider than a week means the simulator, when it falls
 *      back to "the closest previous date" for a missing date, would be serving
 *      a stale price for more than a week — which we treat as missing data.
 *
 * Usage:
 *   php stock_daily_prices_integrity.php [maxGapDays] [maxRowsPerSection]
 *   php stock_daily_prices_integrity.php 7 50
 */

header('Content-Type: text/plain; charset=utf-8');

try {
    $maxGapDays = isset($argv[1]) ? max(1, (int) $argv[1]) : 7;
    $maxRows = isset($argv[2]) ? max(1, (int) $argv[2]) : 50;

    $pdo = connect_pdo();

    $problems = 0;

    echo "stock_daily_prices integrity checker\n";
    echo "====================================\n";
    echo "max allowed gap: {$maxGapDays} days\n\n";

    // ------------------------------------------------------------------
    // Check 1: stocks that have no daily-price rows at all. These are
    // usually delisted/renamed tickers that cannot be fetched, so this is
    // reported for visibility but does not, on its own, fail the run.
    // ------------------------------------------------------------------
    $totalStocks = (int) $pdo->query('SELECT COUNT(*) FROM stocks')->fetchColumn();

    $missingStocks = $pdo->query(
        <<<'SQL'
SELECT s.id, s.symbol
FROM stocks s
LEFT JOIN stock_daily_prices p ON p.stock_id = s.id
WHERE p.stock_id IS NULL
ORDER BY s.symbol
SQL
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "[1] Stocks with no daily prices in the table\n";
    if ($missingStocks === []) {
        echo "    OK - all {$totalStocks} stock(s) have at least one price row.\n\n";
    } else {
        echo sprintf(
            "    %d of %d stock(s) have no price rows yet:\n",
            count($missingStocks),
            $totalStocks
        );
        foreach (array_slice($missingStocks, 0, $maxRows) as $row) {
            echo sprintf("    %s (#%d)\n", (string) $row['symbol'], (int) $row['id']);
        }
        if (count($missingStocks) > $maxRows) {
            echo sprintf("    ... and %d more\n", count($missingStocks) - $maxRows);
        }
        echo "\n";
    }

    // ------------------------------------------------------------------
    // Check 2: duplicate stock symbols (should be blocked by unique key).
    // ------------------------------------------------------------------
    $dupSymbols = $pdo->query(
        <<<'SQL'
SELECT symbol, COUNT(*) AS n
FROM stocks
GROUP BY symbol
HAVING n > 1
ORDER BY n DESC, symbol
SQL
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "[2] Duplicate stock symbols\n";
    if ($dupSymbols === []) {
        echo "    OK - every symbol is unique.\n\n";
    } else {
        $problems += count($dupSymbols);
        echo '    FOUND ' . count($dupSymbols) . " duplicated symbol(s):\n";
        foreach (array_slice($dupSymbols, 0, $maxRows) as $row) {
            echo sprintf("    %s x%d\n", (string) $row['symbol'], (int) $row['n']);
        }
        echo "\n";
    }

    // ------------------------------------------------------------------
    // Check 3: duplicate price rows for the same (stock_id, trade_date).
    // ------------------------------------------------------------------
    $dupPrices = $pdo->query(
        <<<'SQL'
SELECT s.symbol, p.stock_id, p.trade_date, COUNT(*) AS n
FROM stock_daily_prices p
JOIN stocks s ON s.id = p.stock_id
GROUP BY p.stock_id, p.trade_date
HAVING n > 1
ORDER BY n DESC, s.symbol, p.trade_date
SQL
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "[3] Duplicate daily-price rows (same stock + trade_date)\n";
    if ($dupPrices === []) {
        echo "    OK - no duplicate price rows.\n\n";
    } else {
        $problems += count($dupPrices);
        echo '    FOUND ' . count($dupPrices) . " duplicated (stock, date) pair(s):\n";
        foreach (array_slice($dupPrices, 0, $maxRows) as $row) {
            echo sprintf(
                "    %s (#%d) %s x%d\n",
                (string) $row['symbol'],
                (int) $row['stock_id'],
                (string) $row['trade_date'],
                (int) $row['n']
            );
        }
        if (count($dupPrices) > $maxRows) {
            echo sprintf("    ... and %d more\n", count($dupPrices) - $maxRows);
        }
        echo "\n";
    }

    // ------------------------------------------------------------------
    // Check 4: gaps longer than the allowed number of days between
    // consecutive trade dates for a stock.
    // ------------------------------------------------------------------
    $gapStmt = $pdo->prepare(
        <<<'SQL'
SELECT g.stock_id, s.symbol, g.prev_date, g.trade_date,
       DATEDIFF(g.trade_date, g.prev_date) AS gap_days
FROM (
    SELECT stock_id,
           trade_date,
           LAG(trade_date) OVER (PARTITION BY stock_id ORDER BY trade_date) AS prev_date
    FROM stock_daily_prices
) g
JOIN stocks s ON s.id = g.stock_id
WHERE g.prev_date IS NOT NULL
  AND DATEDIFF(g.trade_date, g.prev_date) > :max_gap
ORDER BY gap_days DESC, s.symbol, g.prev_date
SQL
    );
    $gapStmt->execute(['max_gap' => $maxGapDays]);
    $gaps = $gapStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "[4] Gaps longer than {$maxGapDays} days between consecutive trade dates\n";
    if ($gaps === []) {
        echo "    OK - no gaps wider than {$maxGapDays} days.\n\n";
    } else {
        // Aggregate per symbol: count of gaps + the widest one.
        $bySymbol = [];
        foreach ($gaps as $gap) {
            $symbol = (string) $gap['symbol'];
            if (!isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = [
                    'stock_id' => (int) $gap['stock_id'],
                    'count' => 0,
                    'max_gap' => 0,
                    'max_from' => '',
                    'max_to' => '',
                ];
            }

            $bySymbol[$symbol]['count']++;
            $gapDays = (int) $gap['gap_days'];
            if ($gapDays > $bySymbol[$symbol]['max_gap']) {
                $bySymbol[$symbol]['max_gap'] = $gapDays;
                $bySymbol[$symbol]['max_from'] = (string) $gap['prev_date'];
                $bySymbol[$symbol]['max_to'] = (string) $gap['trade_date'];
            }
        }

        $problems += count($gaps);

        echo sprintf(
            "    FOUND %d gap(s) across %d stock(s).\n",
            count($gaps),
            count($bySymbol)
        );
        echo "    Worst offenders (by widest single gap):\n";

        uasort($bySymbol, static fn (array $a, array $b): int => $b['max_gap'] <=> $a['max_gap']);

        $shown = 0;
        foreach ($bySymbol as $symbol => $info) {
            if ($shown >= $maxRows) {
                echo sprintf("    ... and %d more stock(s)\n", count($bySymbol) - $shown);
                break;
            }

            echo sprintf(
                "    %s (#%d): %d gap(s), widest %d days (%s -> %s)\n",
                $symbol,
                $info['stock_id'],
                $info['count'],
                $info['max_gap'],
                $info['max_from'],
                $info['max_to']
            );
            $shown++;
        }
        echo "\n";
    }

    echo "====================================\n";
    if ($missingStocks !== []) {
        echo sprintf(
            "note: %d stock(s) have no price rows yet (see section [1]).\n",
            count($missingStocks)
        );
    }

    if ($problems === 0) {
        echo "PASS - no duplicate/gap integrity problems found.\n";
        exit(0);
    }

    echo "FAIL - {$problems} issue(s) found (see sections above).\n";
    exit(1);
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
