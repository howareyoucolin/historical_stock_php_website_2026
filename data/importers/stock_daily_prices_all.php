<?php

declare(strict_types=1);

require_once __DIR__ . '/stock_daily_prices.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $startDate = trim((string) ($argv[1] ?? '2001-01-01'));
    $endDate = trim((string) ($argv[2] ?? date('Y-m-d')));
    $limit = isset($argv[3]) ? max(0, (int) $argv[3]) : 0;
    $skipExisting = !in_array('--force', $argv, true);

    $start = parse_date($startDate, false);
    $end = parse_date($endDate, true);

    if ($end < $start) {
        throw new RuntimeException('End date must be on or after start date.');
    }

    $pdo = connect_pdo();
    $stocks = fetch_stocks($pdo, $limit);

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0777, true) && !is_dir($logDir)) {
        throw new RuntimeException("Unable to create log directory: {$logDir}");
    }

    $failureLog = $logDir . '/stock_daily_prices_failures.tsv';
    $summaryLog = $logDir . '/stock_daily_prices_summary.log';

    file_put_contents($summaryLog, sprintf(
        "[%s] Starting batch import: %d stocks, %s to %s, skipExisting=%s\n",
        date('c'),
        count($stocks),
        $start->format('Y-m-d'),
        $end->format('Y-m-d'),
        $skipExisting ? 'yes' : 'no'
    ), FILE_APPEND);

    $processed = 0;
    $imported = 0;
    $skipped = 0;
    $failed = 0;
    $rowsFetched = 0;
    $rowsWritten = 0;

    foreach ($stocks as $stock) {
        $processed++;

        $existingCount = count_existing_price_rows($pdo, (int) $stock['id']);
        if ($skipExisting && $existingCount > 0) {
            $skipped++;
            echo sprintf("[%d/%d] %s skipped (%d existing rows)\n", $processed, count($stocks), $stock['symbol'], $existingCount);
            continue;
        }

        try {
            $result = import_stock_daily_prices($pdo, $stock, $start, $end);
            $imported++;
            $rowsFetched += $result['rows_fetched'];
            $rowsWritten += $result['rows_written'];

            echo sprintf(
                "[%d/%d] %s imported via %s (%d fetched, %d written)\n",
                $processed,
                count($stocks),
                $stock['symbol'],
                $result['fetch_symbol'],
                $result['rows_fetched'],
                $result['rows_written']
            );
        } catch (Throwable $e) {
            $failed++;
            $message = $e->getMessage();
            file_put_contents($failureLog, implode("\t", [
                date('c'),
                (string) $stock['id'],
                (string) $stock['symbol'],
                $message,
            ]) . "\n", FILE_APPEND);

            echo sprintf("[%d/%d] %s failed: %s\n", $processed, count($stocks), $stock['symbol'], $message);
        }

        usleep(200000);
    }

    $summary = sprintf(
        "Completed batch import. processed=%d imported=%d skipped=%d failed=%d fetched=%d written=%d\n",
        $processed,
        $imported,
        $skipped,
        $failed,
        $rowsFetched,
        $rowsWritten
    );

    echo $summary;
    file_put_contents($summaryLog, '[' . date('c') . '] ' . $summary, FILE_APPEND);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Batch import failed: ' . $e->getMessage() . "\n";
    exit(1);
}

function fetch_stocks(PDO $pdo, int $limit): array
{
    $sql = 'SELECT id, symbol FROM stocks ORDER BY id';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
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
