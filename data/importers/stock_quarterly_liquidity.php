<?php

declare(strict_types=1);

/**
 * Build per-stock, per-quarter liquidity/volatility stats into
 * stock_quarterly_liquidity:
 *   - realized_vol      = stdev of daily returns in the quarter, annualized (x sqrt(252))
 *   - avg_daily_volume  = mean daily volume in the quarter
 *   - trading_days      = number of return observations in the quarter
 *
 * Usage: php stock_quarterly_liquidity.php
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = connect_pdo();

    $sql = <<<'SQL'
SELECT stock_id, YEAR(trade_date) AS y, QUARTER(trade_date) AS q,
       STDDEV_SAMP(r) AS sd, AVG(volume) AS adv, COUNT(*) AS days
FROM (
    SELECT stock_id, trade_date, volume,
           COALESCE(adj_close, close) / NULLIF(LAG(COALESCE(adj_close, close)) OVER (PARTITION BY stock_id ORDER BY trade_date), 0) - 1 AS r
    FROM stock_daily_prices
) t
WHERE r IS NOT NULL
GROUP BY stock_id, y, q
SQL;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_quarterly_liquidity (stock_id, fiscal_quarter, realized_vol, avg_daily_volume, trading_days)
VALUES (:stock_id, :fiscal_quarter, :realized_vol, :avg_daily_volume, :trading_days)
ON DUPLICATE KEY UPDATE realized_vol = VALUES(realized_vol), avg_daily_volume = VALUES(avg_daily_volume),
    trading_days = VALUES(trading_days), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $qEnd = ['1' => '-03-31', '2' => '-06-30', '3' => '-09-30', '4' => '-12-31'];
    $written = 0;
    foreach ($rows as $row) {
        $vol = $row['sd'] === null ? null : round((float) $row['sd'] * sqrt(252), 6);
        $upsert->execute([
            'stock_id' => (int) $row['stock_id'],
            'fiscal_quarter' => $row['y'] . $qEnd[(string) $row['q']],
            'realized_vol' => $vol,
            'avg_daily_volume' => $row['adv'] === null ? null : (int) round((float) $row['adv']),
            'trading_days' => (int) $row['days'],
        ]);
        $written++;
    }

    echo "Built {$written} quarterly liquidity row(s).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Liquidity build failed: ' . $e->getMessage() . "\n";
    exit(1);
}
