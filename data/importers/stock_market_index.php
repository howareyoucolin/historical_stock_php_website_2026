<?php

declare(strict_types=1);

/**
 * Build an equal-weight benchmark index from the daily price universe and store
 * it in market_index (index_code 'EW'). Each day's return is the winsorized
 * mean daily return across all stocks trading both that day and the prior day
 * (using adj_close for total return), chained into a level starting at 1000.
 *
 * Equal-weight (not cap-weight) is intentional: it needs no market-cap coverage
 * and uses all stocks. Returns are winsorized to +/-50%/day so a single
 * illiquid/synthetic name cannot dominate.
 *
 * Usage: php market_index.php [indexCode] [baseLevel]
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $indexCode = strtoupper(trim((string) ($argv[1] ?? 'EW')));
    $baseLevel = (float) ($argv[2] ?? 1000);

    $pdo = connect_pdo();

    // Mean winsorized daily return per date across the universe.
    $sql = <<<'SQL'
SELECT trade_date,
       AVG(GREATEST(-0.5, LEAST(0.5, r))) AS avg_r,
       COUNT(*) AS n
FROM (
    SELECT trade_date,
           COALESCE(adj_close, close) / NULLIF(LAG(COALESCE(adj_close, close)) OVER (PARTITION BY stock_id ORDER BY trade_date), 0) - 1 AS r
    FROM stock_daily_prices
) t
WHERE r IS NOT NULL
GROUP BY trade_date
ORDER BY trade_date
SQL;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        throw new RuntimeException('No return data to build index.');
    }

    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_market_index (index_code, trade_date, level, daily_return, constituents)
VALUES (:index_code, :trade_date, :level, :daily_return, :constituents)
ON DUPLICATE KEY UPDATE level = VALUES(level), daily_return = VALUES(daily_return),
    constituents = VALUES(constituents), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $level = $baseLevel;
    $written = 0;
    $first = true;
    foreach ($rows as $row) {
        $r = (float) $row['avg_r'];
        if ($first) {
            // anchor the first day at the base level with no prior return
            $r = 0.0;
            $first = false;
        } else {
            $level *= (1 + $r);
        }

        $upsert->execute([
            'index_code' => $indexCode,
            'trade_date' => (string) $row['trade_date'],
            'level' => round($level, 6),
            'daily_return' => round($r, 8),
            'constituents' => (int) $row['n'],
        ]);
        $written++;
    }

    $last = $rows[count($rows) - 1];
    echo "Built {$indexCode} index: {$written} days, "
        . "{$rows[0]['trade_date']} (level {$baseLevel}) -> {$last['trade_date']} (level " . round($level, 2) . ").\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Index build failed: ' . $e->getMessage() . "\n";
    exit(1);
}
