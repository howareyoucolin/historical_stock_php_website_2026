<?php

declare(strict_types=1);

/**
 * Build the quarterly valuation table (PE, forward PE, PEG) from quarterly TTM
 * EPS plus the daily price series.
 *
 * Quarterly is the right grain: EPS only changes when a company reports. The
 * ratios are computed at each fiscal quarter-end using that quarter's price:
 *   PE         = quarter-end close / TTM EPS
 *   eps_growth = YoY change in TTM EPS (%)             (vs the same quarter a year earlier)
 *   forward_eps= TTM EPS projected one year by growth   (synthetic estimate)
 *   forward_PE = quarter-end close / forward EPS
 *   PEG        = PE / eps_growth
 * Ratios are NULL where undefined (non-positive earnings / growth).
 *
 * Source EPS: data/raw/quarterly-eps.json ({ SYMBOL: { "YYYY-MM-DD": ttmEps } }).
 *
 * Usage: php stock_quarterly_fundamentals.php [epsJsonPath]
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $jsonPath = (string) ($argv[1] ?? dirname(__DIR__) . '/raw/quarterly-eps.json');
    if (!is_file($jsonPath)) {
        throw new RuntimeException("EPS file not found: {$jsonPath}");
    }
    $epsBySymbol = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($epsBySymbol)) {
        throw new RuntimeException('EPS JSON must be an object of {symbol: {date: eps}}.');
    }

    $pdo = connect_pdo();
    $symbolToId = [];
    foreach ($pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $symbolToId[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    $closeStmt = $pdo->prepare(
        'SELECT close FROM stock_daily_prices WHERE stock_id = :id AND trade_date <= :q ORDER BY trade_date DESC LIMIT 1'
    );
    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_quarterly_metrics
    (stock_id, fiscal_quarter, close, eps_ttm, eps_growth, forward_eps, pe, forward_pe, peg)
VALUES
    (:stock_id, :fiscal_quarter, :close, :eps_ttm, :eps_growth, :forward_eps, :pe, :forward_pe, :peg)
ON DUPLICATE KEY UPDATE
    close = VALUES(close), eps_ttm = VALUES(eps_ttm), eps_growth = VALUES(eps_growth),
    forward_eps = VALUES(forward_eps), pe = VALUES(pe), forward_pe = VALUES(forward_pe),
    peg = VALUES(peg), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $stats = ['symbols' => 0, 'rows' => 0, 'no_id' => 0, 'no_price' => 0];

    foreach ($epsBySymbol as $symbol => $epsByDate) {
        $symbol = strtoupper(trim((string) $symbol));
        if (!is_array($epsByDate) || $epsByDate === []) {
            continue;
        }
        $stockId = $symbolToId[$symbol] ?? ($symbolToId[strtoupper(resolve_price_symbol($symbol))] ?? null);
        if ($stockId === null) {
            $stats['no_id']++;
            continue;
        }

        // chronological quarter list
        $quarters = array_keys($epsByDate);
        sort($quarters, SORT_STRING);
        $epsVals = array_map(static fn ($q) => (float) $epsByDate[$q], $quarters);

        $wrote = 0;
        foreach ($quarters as $i => $q) {
            $eps = $epsVals[$i];

            $closeStmt->execute(['id' => $stockId, 'q' => $q]);
            $closeRaw = $closeStmt->fetchColumn();
            $close = $closeRaw === false ? null : (float) $closeRaw;

            $pe = ($close !== null && $eps > 0) ? round($close / $eps, 4) : null;

            // YoY growth vs four quarters earlier
            $growth = null;
            if ($i >= 4 && $epsVals[$i - 4] > 0) {
                $growth = round(($eps / $epsVals[$i - 4] - 1) * 100, 4);
            }

            $forwardEps = ($growth !== null && $eps > 0) ? round($eps * (1 + $growth / 100), 4) : null;
            $forwardPe = ($close !== null && $forwardEps !== null && $forwardEps > 0) ? round($close / $forwardEps, 4) : null;
            $peg = ($pe !== null && $growth !== null && $growth > 0) ? round($pe / $growth, 4) : null;

            $upsert->execute([
                'stock_id' => $stockId,
                'fiscal_quarter' => $q,
                'close' => $close,
                'eps_ttm' => round($eps, 4),
                'eps_growth' => $growth,
                'forward_eps' => $forwardEps,
                'pe' => $pe,
                'forward_pe' => $forwardPe,
                'peg' => $peg,
            ]);
            if ($close === null) {
                $stats['no_price']++;
            }
            $wrote++;
        }

        $stats['symbols']++;
        $stats['rows'] += $wrote;
    }

    echo "=== Quarterly fundamentals ===\n";
    foreach ($stats as $k => $v) {
        echo "{$k}: {$v}\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fundamentals build failed: ' . $e->getMessage() . "\n";
    exit(1);
}
