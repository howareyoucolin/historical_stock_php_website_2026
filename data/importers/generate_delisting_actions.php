<?php

declare(strict_types=1);

/**
 * Derive "delisted" corporate actions from price history.
 *
 * Any stock whose daily-price series ends well before the latest market date
 * effectively stopped trading on its last available date, so we record a
 * `delisted` corporate action with that date. Stocks that already carry a
 * corporate action (buyout/swap/wipeout/etc.) are left alone, since their
 * terminal event is already modeled more specifically.
 *
 * Usage: php generate_delisting_actions.php [staleDays]   (default 30)
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $staleDays = isset($argv[1]) ? max(1, (int) $argv[1]) : 30;

    $pdo = connect_pdo();

    $latest = (string) $pdo->query('SELECT MAX(trade_date) FROM stock_daily_prices')->fetchColumn();
    if ($latest === '') {
        throw new RuntimeException('stock_daily_prices is empty; nothing to derive.');
    }

    // Stocks whose series ended before the cutoff and that have no action yet.
    $stmt = $pdo->prepare(
        <<<'SQL'
SELECT s.id AS stock_id, s.symbol, t.last_date
FROM (
    SELECT stock_id, MAX(trade_date) AS last_date
    FROM stock_daily_prices
    GROUP BY stock_id
) t
JOIN stocks s ON s.id = t.stock_id
LEFT JOIN stock_corporate_actions ca ON ca.stock_id = t.stock_id
WHERE t.last_date < (:latest - INTERVAL :stale_days DAY)
  AND ca.id IS NULL
ORDER BY t.last_date DESC, s.symbol
SQL
    );
    $stmt->bindValue(':latest', $latest);
    $stmt->bindValue(':stale_days', $staleDays, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($candidates === []) {
        echo "No newly delisted stocks to record.\n";
        exit(0);
    }

    $insertStmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_corporate_actions (stock_id, stock_code, action_date, type, note)
VALUES (:stock_id, :stock_code, :action_date, 'delisted', :note)
ON DUPLICATE KEY UPDATE
    stock_id = VALUES(stock_id),
    note = VALUES(note),
    updated_at = CURRENT_TIMESTAMP
SQL
    );

    $written = 0;
    foreach ($candidates as $row) {
        $insertStmt->execute([
            'stock_id' => (int) $row['stock_id'],
            'stock_code' => (string) $row['symbol'],
            'action_date' => (string) $row['last_date'],
            'note' => 'Derived from last available trade date (series ended).',
        ]);
        $written++;
        echo sprintf("%s (#%d) delisted %s\n", $row['symbol'], (int) $row['stock_id'], $row['last_date']);
    }

    echo "\nRecorded {$written} delisted action(s) (latest market date {$latest}, stale threshold {$staleDays} days).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Delisting generation failed: ' . $e->getMessage() . "\n";
    exit(1);
}
