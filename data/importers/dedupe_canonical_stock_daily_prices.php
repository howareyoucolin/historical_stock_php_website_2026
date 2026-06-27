<?php

declare(strict_types=1);

require_once __DIR__ . '/stock_daily_prices.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = connect_pdo();

    $pairs = $pdo->query(
        'SELECT id, symbol, canonical_stock_id FROM stocks WHERE canonical_stock_id IS NOT NULL ORDER BY symbol'
    )->fetchAll(PDO::FETCH_ASSOC);

    $insertStmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_daily_prices (stock_id, trade_date, close, adj_close, volume, created_at, updated_at)
SELECT :canonical_stock_id, trade_date, close, adj_close, volume, created_at, updated_at
FROM stock_daily_prices AS source_prices
WHERE stock_id = :alias_stock_id
ON DUPLICATE KEY UPDATE
    close = VALUES(close),
    adj_close = VALUES(adj_close),
    volume = VALUES(volume),
    updated_at = VALUES(updated_at)
SQL
    );
    $deleteStmt = $pdo->prepare('DELETE FROM stock_daily_prices WHERE stock_id = :alias_stock_id');
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM stock_daily_prices WHERE stock_id = :stock_id');

    $processed = 0;
    $deleted = 0;

    foreach ($pairs as $pair) {
        $aliasId = (int) $pair['id'];
        $canonicalId = (int) $pair['canonical_stock_id'];
        $symbol = (string) $pair['symbol'];

        $countStmt->execute(['stock_id' => $aliasId]);
        $aliasRows = (int) $countStmt->fetchColumn();
        if ($aliasRows === 0) {
            continue;
        }

        $insertStmt->execute([
            'canonical_stock_id' => $canonicalId,
            'alias_stock_id' => $aliasId,
        ]);
        $deleteStmt->execute(['alias_stock_id' => $aliasId]);
        $deleted += $deleteStmt->rowCount();
        $processed++;

        echo sprintf("%s alias rows moved/deleted: %d\n", $symbol, $aliasRows);
    }

    echo sprintf("Processed alias stocks: %d\nDeleted alias price rows: %d\n", $processed, $deleted);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Canonical dedupe failed: ' . $e->getMessage() . "\n";
    exit(1);
}
