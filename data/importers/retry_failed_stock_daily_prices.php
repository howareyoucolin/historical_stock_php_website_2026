<?php

declare(strict_types=1);

require_once __DIR__ . '/stock_daily_prices.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $attempts = max(1, (int) ($argv[1] ?? 2));
    $startDate = trim((string) ($argv[2] ?? '2001-01-01'));
    $endDate = trim((string) ($argv[3] ?? date('Y-m-d')));

    $start = parse_date($startDate, false);
    $end = parse_date($endDate, true);

    if ($end < $start) {
        throw new RuntimeException('End date must be on or after start date.');
    }

    $failureLog = __DIR__ . '/logs/stock_daily_prices_failures.tsv';
    if (!is_file($failureLog)) {
        throw new RuntimeException('Failure log not found.');
    }

    $symbols = read_failed_symbols($failureLog);
    if ($symbols === []) {
        echo "No failed symbols to retry.\n";
        exit(0);
    }

    $pdo = connect_pdo();
    $stocks = fetch_stocks_by_symbols($pdo, $symbols);

    $retryLog = __DIR__ . '/logs/stock_daily_prices_retry.log';
    file_put_contents($retryLog, sprintf(
        "[%s] Starting retry run: symbols=%d attempts=%d range=%s..%s\n",
        date('c'),
        count($symbols),
        $attempts,
        $start->format('Y-m-d'),
        $end->format('Y-m-d')
    ), FILE_APPEND);

    $resolved = [];
    $stillFailing = [];
    $missingStocks = [];

    foreach ($symbols as $symbol) {
        $stock = $stocks[$symbol] ?? null;
        if ($stock === null) {
            $missingStocks[] = $symbol;
            file_put_contents($retryLog, sprintf("[%s] %s missing from stocks table\n", date('c'), $symbol), FILE_APPEND);
            echo "{$symbol} missing from stocks table\n";
            continue;
        }

        $success = false;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $beforeCount = count_existing_price_rows($pdo, (int) $stock['id']);
                $result = import_stock_daily_prices($pdo, $stock, $start, $end);
                $afterCount = count_existing_price_rows($pdo, (int) $stock['id']);

                $success = true;
                $resolved[$symbol] = [
                    'attempt' => $attempt,
                    'fetched' => $result['rows_fetched'],
                    'written' => $result['rows_written'],
                    'before' => $beforeCount,
                    'after' => $afterCount,
                    'fetch_symbol' => $result['fetch_symbol'],
                    'target_symbol' => $result['target_symbol'],
                ];

                file_put_contents($retryLog, sprintf(
                    "[%s] %s resolved on attempt %d via %s stored_as=%s fetched=%d written=%d before=%d after=%d\n",
                    date('c'),
                    $symbol,
                    $attempt,
                    $result['fetch_symbol'],
                    $result['target_symbol'],
                    $result['rows_fetched'],
                    $result['rows_written'],
                    $beforeCount,
                    $afterCount
                ), FILE_APPEND);

                echo sprintf(
                    "%s resolved on attempt %d via %s stored as %s (%d fetched, %d written, rows now %d)\n",
                    $symbol,
                    $attempt,
                    $result['fetch_symbol'],
                    $result['target_symbol'],
                    $result['rows_fetched'],
                    $result['rows_written'],
                    $afterCount
                );
                break;
            } catch (Throwable $e) {
                $stillFailing[$symbol] = $e->getMessage();
                file_put_contents($retryLog, sprintf(
                    "[%s] %s failed on attempt %d: %s\n",
                    date('c'),
                    $symbol,
                    $attempt,
                    $e->getMessage()
                ), FILE_APPEND);
                echo sprintf("%s failed on attempt %d: %s\n", $symbol, $attempt, $e->getMessage());
                usleep(300000);
            }
        }

        if (!$success) {
            file_put_contents($retryLog, sprintf("[%s] %s still failing after %d attempts\n", date('c'), $symbol, $attempts), FILE_APPEND);
        }
    }

    echo "\nRetry summary\n";
    echo 'Failed symbols considered: ' . count($symbols) . "\n";
    echo 'Resolved: ' . count($resolved) . "\n";
    echo 'Still failing: ' . count($stillFailing) . "\n";
    echo 'Missing from stocks table: ' . count($missingStocks) . "\n";

    if ($resolved !== []) {
        echo "\nResolved symbols\n";
        foreach ($resolved as $symbol => $info) {
            echo sprintf(
                "%s | attempt=%d | target=%s | fetched=%d | written=%d | rows_now=%d\n",
                $symbol,
                $info['attempt'],
                $info['target_symbol'],
                $info['fetched'],
                $info['written'],
                $info['after']
            );
        }
    }

    if ($stillFailing !== []) {
        echo "\nStill failing symbols\n";
        foreach ($stillFailing as $symbol => $message) {
            echo sprintf("%s | %s\n", $symbol, $message);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Retry failed: ' . $e->getMessage() . "\n";
    exit(1);
}

function read_failed_symbols(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read failure log.');
    }

    $symbols = [];

    foreach ($lines as $line) {
        $parts = explode("\t", $line);
        if (count($parts) < 3) {
            continue;
        }

        $symbol = trim($parts[2]);
        if ($symbol === '') {
            continue;
        }

        $symbols[$symbol] = true;
    }

    $result = array_keys($symbols);
    sort($result, SORT_STRING);

    return $result;
}

function fetch_stocks_by_symbols(PDO $pdo, array $symbols): array
{
    if ($symbols === []) {
        return [];
    }

    $lookupMap = [];
    foreach ($symbols as $symbol) {
        $normalizedSymbol = normalize_stock_symbol((string) $symbol);
        if ($normalizedSymbol === '') {
            continue;
        }

        $lookupMap[$normalizedSymbol] = resolve_price_symbol($normalizedSymbol);
    }

    $lookupSymbols = array_values(array_unique(array_merge(array_keys($lookupMap), array_values($lookupMap))));
    $placeholders = implode(', ', array_fill(0, count($lookupSymbols), '?'));
    $stmt = $pdo->prepare("SELECT id, symbol FROM stocks WHERE symbol IN ({$placeholders})");
    $stmt->execute($lookupSymbols);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rowsBySymbol = [];

    foreach ($rows as $row) {
        if (is_array($row) && isset($row['symbol'])) {
            $rowsBySymbol[(string) $row['symbol']] = $row;
        }
    }

    $bySymbol = [];
    foreach ($lookupMap as $originalSymbol => $resolvedSymbol) {
        if (isset($rowsBySymbol[$originalSymbol])) {
            $bySymbol[$originalSymbol] = $rowsBySymbol[$originalSymbol];
            continue;
        }

        if (isset($rowsBySymbol[$resolvedSymbol])) {
            $bySymbol[$originalSymbol] = $rowsBySymbol[$resolvedSymbol];
        }
    }

    return $bySymbol;
}

function count_existing_price_rows(PDO $pdo, int $stockId): int
{
    static $stmt = null;

    if (!$stmt instanceof PDOStatement) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM stock_daily_prices WHERE stock_id = :stock_id');
    }

    $stmt->execute(['stock_id' => $stockId]);

    return (int) $stmt->fetchColumn();
}
