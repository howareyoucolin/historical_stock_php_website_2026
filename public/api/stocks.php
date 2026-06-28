<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// List every stock symbol that has at least one daily price row, i.e. every code the simulator
// can actually trade. Mirrors the old "market-data folder with a built data.json" availability rule.
$pdo = connect_pdo();

$rows = $pdo->query(
    <<<'SQL'
SELECT s.symbol AS symbol,
       s.sector AS sector
FROM stocks s
WHERE EXISTS (
    SELECT 1 FROM stock_daily_prices p WHERE p.stock_id = s.id
)
ORDER BY s.symbol ASC
SQL
)->fetchAll(PDO::FETCH_ASSOC);

$stocks = array_map(static fn (array $row): string => (string) $row['symbol'], $rows);
$entries = array_map(
    static fn (array $row): array => [
        'code' => (string) $row['symbol'],
        'sector' => $row['sector'] !== null ? (string) $row['sector'] : null,
    ],
    $rows
);

api_json(['stocks' => $stocks, 'entries' => $entries]);
