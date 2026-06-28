<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Serve the market benchmark (the equal-weight S&P 500 index) in the same daily shape the simulator
// uses for stocks, so report building can value a buy-and-hold benchmark the same way it values a
// stock. `close` carries the index level. This replaces the old SPY-ETF benchmark, which is not an
// S&P 500 constituent and therefore not in the dataset.

$indexCode = strtoupper(trim((string) ($_GET['index'] ?? 'EW')));
if (!preg_match('/^[A-Z0-9]+$/', $indexCode)) {
    api_error('Invalid index code.', 400);
}

$pdo = connect_pdo();

$stmt = $pdo->prepare(
    'SELECT trade_date, level FROM stock_market_index WHERE index_code = :code ORDER BY trade_date ASC'
);
$stmt->execute(['code' => $indexCode]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    api_error("No data for index {$indexCode}", 404);
}

$historyByDate = [];
foreach ($rows as $row) {
    $historyByDate[(string) $row['trade_date']] = [
        'close' => (float) $row['level'],
        'isPayoutDate' => false,
        'dividendPerShare' => 0,
        'ttmEps' => null,
        'peRatio' => null,
        'sharesOutstanding' => null,
        'marketCap' => null,
    ];
}

$dates = array_keys($historyByDate);

api_json([
    'stockCode' => $indexCode,
    'source' => 'database',
    'range' => ['start' => $dates[0], 'end' => $dates[count($dates) - 1]],
    'historyByDate' => $historyByDate,
]);
