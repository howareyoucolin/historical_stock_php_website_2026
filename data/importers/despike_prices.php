<?php

declare(strict_types=1);

/**
 * Repair isolated single-day price spikes (bad ticks) in stock_daily_prices.
 * A spike is a day whose close is wildly out of line with BOTH neighbouring
 * days (e.g. $10 -> $0.11 -> $8.50): it reverts immediately, so it is a data
 * error rather than a real move. We replace such a close (and adj_close) with
 * the geometric mean of the two neighbours. Sustained moves (a real crash that
 * stays down) are left untouched because only one neighbour differs.
 *
 * Flag thresholds: close < 0.4x the smaller neighbour, or > 2.5x the larger.
 *
 * Usage: php despike_prices.php [lowFactor] [highFactor]
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $low = (float) ($argv[1] ?? 0.4);
    $high = (float) ($argv[2] ?? 2.5);

    $pdo = connect_pdo();
    $stockIds = $pdo->query('SELECT DISTINCT stock_id FROM stock_daily_prices ORDER BY stock_id')->fetchAll(PDO::FETCH_COLUMN);

    $rowsStmt = $pdo->prepare('SELECT id, close, adj_close FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date');
    $upd = $pdo->prepare('UPDATE stock_daily_prices SET close = :c, adj_close = :a WHERE id = :id');

    $totalFixed = 0;
    $stocksTouched = 0;

    foreach ($stockIds as $sid) {
        $rowsStmt->execute(['id' => (int) $sid]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        $count = count($rows);
        if ($count < 3) {
            continue;
        }

        $fixed = 0;
        for ($i = 1; $i < $count - 1; $i++) {
            $prev = (float) $rows[$i - 1]['close'];
            $cur = (float) $rows[$i]['close'];
            $next = (float) $rows[$i + 1]['close'];
            if ($prev <= 0 || $next <= 0 || $cur <= 0) {
                continue;
            }

            $lo = min($prev, $next);
            $hi = max($prev, $next);
            if ($cur < $low * $lo || $cur > $high * $hi) {
                $newClose = sqrt($prev * $next);
                // keep the adj/close relationship roughly intact
                $ratio = $cur > 0 ? ((float) $rows[$i]['adj_close']) / $cur : 1.0;
                if (!is_finite($ratio) || $ratio <= 0) {
                    $ratio = 1.0;
                }
                $upd->execute([
                    'c' => round($newClose, 4),
                    'a' => round($newClose * $ratio, 4),
                    'id' => (int) $rows[$i]['id'],
                ]);
                $fixed++;
            }
        }

        if ($fixed > 0) {
            $totalFixed += $fixed;
            $stocksTouched++;
        }
    }

    echo "Despike complete: fixed {$totalFixed} bad tick(s) across {$stocksTouched} stock(s).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Despike failed: ' . $e->getMessage() . "\n";
    exit(1);
}
