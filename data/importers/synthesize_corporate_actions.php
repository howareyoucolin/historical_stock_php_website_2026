<?php

declare(strict_types=1);

/**
 * Materialize synthetic daily prices for the terminal life of stocks that hit a
 * corporate action, so the served series stays complete past the event:
 *
 *   - cash_buyout     -> held flat at cashPerShare from the action date onward
 *   - equity_wipeout  -> 0 from the action date onward
 *   - stock_swap      -> acquirer's real close x shareRatio from the action date
 *
 * Synthetic rows never overwrite real prices (INSERT IGNORE). The trading
 * calendar is taken from the most complete live stock in the table, so the
 * synthetic dates line up with real market days.
 *
 * Usage: php synthesize_corporate_actions.php [endDate]
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $endDate = trim((string) ($argv[1] ?? date('Y-m-d')));

    $pdo = connect_pdo();

    $calendar = load_trading_calendar($pdo);
    if ($calendar === []) {
        throw new RuntimeException('No trading calendar available (stock_daily_prices is empty).');
    }
    $calendarEnd = min($endDate, $calendar[count($calendar) - 1]);

    $actions = load_actionable_corporate_actions($pdo);
    if ($actions === []) {
        echo "No actionable corporate actions found.\n";
        exit(0);
    }

    $insertStmt = $pdo->prepare(
        <<<'SQL'
INSERT IGNORE INTO stock_daily_prices (
    stock_id, trade_date, close, adj_close, volume
) VALUES (
    :stock_id, :trade_date, :close, :adj_close, 0
)
SQL
    );

    $totalWritten = 0;

    foreach ($actions as $action) {
        $stockId = (int) $action['stock_id'];
        $type = (string) $action['type'];
        $start = (string) $action['action_date'];
        $dates = calendar_slice($calendar, $start, $calendarEnd);

        if ($dates === []) {
            echo sprintf("%s (#%d) %s: no calendar dates in range, skipped\n", $action['stock_code'], $stockId, $type);
            continue;
        }

        $written = 0;

        if ($type === 'cash_buyout') {
            $value = round((float) $action['cash_per_share'], 4);
            foreach ($dates as $date) {
                $insertStmt->execute(['stock_id' => $stockId, 'trade_date' => $date, 'close' => $value, 'adj_close' => $value]);
                $written += $insertStmt->rowCount();
            }
        } elseif ($type === 'equity_wipeout') {
            foreach ($dates as $date) {
                $insertStmt->execute(['stock_id' => $stockId, 'trade_date' => $date, 'close' => 0, 'adj_close' => 0]);
                $written += $insertStmt->rowCount();
            }
        } elseif ($type === 'stock_swap') {
            $acquirerId = $action['acquirer_stock_id'] !== null ? (int) $action['acquirer_stock_id'] : null;
            if ($acquirerId === null) {
                echo sprintf("%s (#%d) stock_swap: acquirer %s not in stocks, skipped\n", $action['stock_code'], $stockId, (string) $action['acquirer_stock_code']);
                continue;
            }

            $ratio = (float) $action['share_ratio'];
            $acquirerPrices = load_prices_by_date($pdo, $acquirerId, $start, $calendarEnd);

            foreach ($dates as $date) {
                if (!isset($acquirerPrices[$date])) {
                    continue;
                }
                $close = round($acquirerPrices[$date]['close'] * $ratio, 4);
                $adj = $acquirerPrices[$date]['adj_close'] === null ? $close : round($acquirerPrices[$date]['adj_close'] * $ratio, 4);
                $insertStmt->execute(['stock_id' => $stockId, 'trade_date' => $date, 'close' => $close, 'adj_close' => $adj]);
                $written += $insertStmt->rowCount();
            }
        } else {
            continue;
        }

        $totalWritten += $written;
        echo sprintf("%s (#%d) %s from %s: %d synthetic row(s) written\n", $action['stock_code'], $stockId, $type, $start, $written);
    }

    echo "\nDone. Total synthetic rows written: {$totalWritten}\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Synthesis failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Use the most complete stock's trade dates as the market trading calendar.
function load_trading_calendar(PDO $pdo): array
{
    $referenceId = (int) $pdo->query(
        'SELECT stock_id FROM stock_daily_prices GROUP BY stock_id ORDER BY COUNT(*) DESC LIMIT 1'
    )->fetchColumn();

    if ($referenceId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT trade_date FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date');
    $stmt->execute(['id' => $referenceId]);

    return array_map(static fn ($d): string => (string) $d, $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Corporate actions that imply a synthetic price path, joined to resolved ids.
function load_actionable_corporate_actions(PDO $pdo): array
{
    $stmt = $pdo->query(
        <<<'SQL'
SELECT stock_id, stock_code, action_date, type, cash_per_share,
       acquirer_stock_code, acquirer_stock_id, share_ratio
FROM stock_corporate_actions
WHERE stock_id IS NOT NULL
  AND type IN ('cash_buyout', 'equity_wipeout', 'stock_swap')
ORDER BY action_date
SQL
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Map of trade_date => [close, adj_close] for a stock within a date range.
function load_prices_by_date(PDO $pdo, int $stockId, string $start, string $end): array
{
    $stmt = $pdo->prepare(
        'SELECT trade_date, close, adj_close FROM stock_daily_prices WHERE stock_id = :id AND trade_date >= :start AND trade_date <= :end'
    );
    $stmt->execute(['id' => $stockId, 'start' => $start, 'end' => $end]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['trade_date']] = [
            'close' => (float) $row['close'],
            'adj_close' => $row['adj_close'] === null ? null : (float) $row['adj_close'],
        ];
    }

    return $map;
}

// Calendar dates on or after the action date and on or before the end date.
function calendar_slice(array $calendar, string $start, string $end): array
{
    $out = [];
    foreach ($calendar as $date) {
        if ($date >= $start && $date <= $end) {
            $out[] = $date;
        }
    }

    return $out;
}
