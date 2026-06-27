<?php

declare(strict_types=1);

/**
 * Load dividend events into stock_dividends.
 *
 * Source: data/raw/dividends.json ({ SYMBOL: [[ "YYYY-MM-DD", amount ], ...] }),
 * aggregated from the simulator market data. The source records a single date
 * per dividend; we treat it as the EX-date (the price-adjustment date) and
 * derive pay_date as ex_date + ~21 days (typical lag; estimated, not official).
 *
 * Usage: php stock_dividends.php [dividendsJsonPath]
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $jsonPath = (string) ($argv[1] ?? dirname(__DIR__) . '/raw/dividends.json');
    if (!is_file($jsonPath)) {
        throw new RuntimeException("Dividends file not found: {$jsonPath}");
    }
    $bySymbol = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($bySymbol)) {
        throw new RuntimeException('Dividends JSON must be { symbol: [[date, amount], ...] }.');
    }

    $pdo = connect_pdo();
    $symbolToId = [];
    foreach ($pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $symbolToId[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_dividends (stock_id, ex_date, amount, pay_date)
VALUES (:stock_id, :ex_date, :amount, :pay_date)
ON DUPLICATE KEY UPDATE amount = VALUES(amount), pay_date = VALUES(pay_date), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $stats = ['symbols' => 0, 'events' => 0, 'no_id' => 0];

    foreach ($bySymbol as $symbol => $events) {
        $symbol = strtoupper(trim((string) $symbol));
        if (!is_array($events) || $events === []) {
            continue;
        }
        $stockId = $symbolToId[$symbol] ?? ($symbolToId[strtoupper(resolve_price_symbol($symbol))] ?? null);
        if ($stockId === null) {
            $stats['no_id']++;
            continue;
        }

        foreach ($events as $ev) {
            if (!is_array($ev) || count($ev) < 2) {
                continue;
            }
            $exDate = trim((string) $ev[0]);
            $amount = (float) $ev[1];
            $ts = strtotime($exDate);
            if ($ts === false || $amount <= 0) {
                continue;
            }
            $exDate = date('Y-m-d', $ts);
            $payDate = date('Y-m-d', $ts + 21 * 86400);

            $upsert->execute([
                'stock_id' => $stockId,
                'ex_date' => $exDate,
                'amount' => round($amount, 6),
                'pay_date' => $payDate,
            ]);
            $stats['events']++;
        }
        $stats['symbols']++;
    }

    echo "=== Dividends ===\n";
    foreach ($stats as $k => $v) {
        echo "{$k}: {$v}\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Dividends import failed: ' . $e->getMessage() . "\n";
    exit(1);
}
