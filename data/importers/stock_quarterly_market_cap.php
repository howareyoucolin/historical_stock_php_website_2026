<?php

declare(strict_types=1);

/**
 * Build quarterly shares-outstanding / market-cap rows.
 *
 * Source: data/raw/shares-outstanding.json ({ SYMBOL: sharesInMillions }). This
 * is a single (current) share count per ticker, so shares_outstanding is held
 * flat across quarters and market_cap = quarter-end close * shares (it moves
 * with price, not with buybacks/issuance). One row per calendar quarter-end
 * (Mar/Jun/Sep/Dec) within the stock's price history.
 *
 * Usage: php stock_quarterly_market_cap.php [sharesJsonPath]
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $jsonPath = (string) ($argv[1] ?? dirname(__DIR__) . '/raw/shares-outstanding.json');
    if (!is_file($jsonPath)) {
        throw new RuntimeException("Shares file not found: {$jsonPath}");
    }
    $sharesM = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($sharesM)) {
        throw new RuntimeException('Shares JSON must be { symbol: sharesInMillions }.');
    }

    $pdo = connect_pdo();
    $symbolToId = [];
    foreach ($pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $symbolToId[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    $rangeStmt = $pdo->prepare('SELECT MIN(trade_date) mn, MAX(trade_date) mx FROM stock_daily_prices WHERE stock_id = :id');
    $closeStmt = $pdo->prepare('SELECT close FROM stock_daily_prices WHERE stock_id = :id AND trade_date <= :q ORDER BY trade_date DESC LIMIT 1');
    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_quarterly_market_cap (stock_id, fiscal_quarter, close, shares_outstanding, market_cap)
VALUES (:stock_id, :fiscal_quarter, :close, :shares_outstanding, :market_cap)
ON DUPLICATE KEY UPDATE close = VALUES(close), shares_outstanding = VALUES(shares_outstanding),
    market_cap = VALUES(market_cap), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $stats = ['symbols' => 0, 'rows' => 0, 'no_id' => 0, 'no_price' => 0];

    foreach ($sharesM as $symbol => $millions) {
        $symbol = strtoupper(trim((string) $symbol));
        if (!is_numeric($millions) || (float) $millions <= 0) {
            continue;
        }
        $stockId = $symbolToId[$symbol] ?? ($symbolToId[strtoupper(resolve_price_symbol($symbol))] ?? null);
        if ($stockId === null) {
            $stats['no_id']++;
            continue;
        }

        $rangeStmt->execute(['id' => $stockId]);
        $range = $rangeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$range || $range['mn'] === null) {
            $stats['no_price']++;
            continue;
        }

        $shares = (int) round((float) $millions * 1_000_000);
        $wrote = 0;
        foreach (quarter_ends((string) $range['mn'], (string) $range['mx']) as $q) {
            $closeStmt->execute(['id' => $stockId, 'q' => $q]);
            $closeRaw = $closeStmt->fetchColumn();
            if ($closeRaw === false) {
                continue;
            }
            $close = (float) $closeRaw;

            $upsert->execute([
                'stock_id' => $stockId,
                'fiscal_quarter' => $q,
                'close' => round($close, 4),
                'shares_outstanding' => $shares,
                'market_cap' => round($close * $shares, 2),
            ]);
            $wrote++;
        }

        $stats['symbols']++;
        $stats['rows'] += $wrote;
    }

    echo "=== Quarterly market cap ===\n";
    foreach ($stats as $k => $v) {
        echo "{$k}: {$v}\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Market cap build failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Calendar quarter-end dates (Mar 31, Jun 30, Sep 30, Dec 31) within [start, end].
function quarter_ends(string $start, string $end): array
{
    $out = [];
    $year = (int) substr($start, 0, 4);
    $endYear = (int) substr($end, 0, 4);
    for (; $year <= $endYear; $year++) {
        foreach (['-03-31', '-06-30', '-09-30', '-12-31'] as $suffix) {
            $q = $year . $suffix;
            if ($q >= $start && $q <= $end) {
                $out[] = $q;
            }
        }
    }

    return $out;
}
