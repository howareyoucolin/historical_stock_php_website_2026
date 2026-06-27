<?php

declare(strict_types=1);

/**
 * Load point-in-time S&P 500 membership from data/raw/sp500-members.json into
 * index_membership: one row per (index, symbol, year), with the year's snapshot
 * date and a resolved stock_id where the symbol exists in stocks. Lets callers
 * ask "what was in the index in year N" for survivorship-correct universes.
 *
 * Usage: php index_membership.php [membersJsonPath]
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $jsonPath = (string) ($argv[1] ?? dirname(__DIR__) . '/raw/sp500-members.json');
    if (!is_file($jsonPath)) {
        throw new RuntimeException("Members file not found: {$jsonPath}");
    }
    $decoded = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Members JSON invalid.');
    }
    $meta = is_array($decoded['_meta'] ?? null) ? $decoded['_meta'] : [];
    $snapshotDates = is_array($meta['snapshot_dates'] ?? null) ? $meta['snapshot_dates'] : [];

    $pdo = connect_pdo();
    $symbolToId = [];
    foreach ($pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $symbolToId[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_index_membership (index_code, symbol, stock_id, snapshot_year, snapshot_date)
VALUES (:index_code, :symbol, :stock_id, :snapshot_year, :snapshot_date)
ON DUPLICATE KEY UPDATE stock_id = VALUES(stock_id), snapshot_date = VALUES(snapshot_date), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $rows = 0;
    $linked = 0;
    foreach ($decoded as $year => $tickers) {
        if ($year === '_meta' || !is_array($tickers)) {
            continue;
        }
        $snapDate = isset($snapshotDates[$year]) ? (string) $snapshotDates[$year] : null;

        foreach ($tickers as $ticker) {
            $symbol = normalize_stock_symbol((string) $ticker);
            if ($symbol === '') {
                continue;
            }
            $stockId = $symbolToId[strtoupper($symbol)]
                ?? ($symbolToId[strtoupper(resolve_price_symbol($symbol))] ?? null);
            if ($stockId !== null) {
                $linked++;
            }

            $upsert->execute([
                'index_code' => 'SP500',
                'symbol' => strtoupper($symbol),
                'stock_id' => $stockId,
                'snapshot_year' => (int) $year,
                'snapshot_date' => $snapDate,
            ]);
            $rows++;
        }
    }

    echo "Loaded {$rows} membership rows ({$linked} linked to a stock_id).\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Membership load failed: ' . $e->getMessage() . "\n";
    exit(1);
}
