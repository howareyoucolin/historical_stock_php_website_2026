<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Serve one stock's full daily series in the exact shape the simulator's old market-data/<CODE>/data.json
// carried, rebuilt from the database:
//   { stockCode, range:{start,end}, historyByDate: { "YYYY-MM-DD": {
//       close, isPayoutDate, dividendPerShare, ttmEps, peRatio, sharesOutstanding, marketCap } } }
// close comes from stock_daily_prices; dividends from stock_dividends; ttmEps and shares are
// forward-filled from the quarterly tables; peRatio and marketCap are derived per day.

$symbol = api_require_symbol();
$pdo = connect_pdo();

$stock = api_find_stock($pdo, $symbol);
if ($stock === null) {
    api_error("Unknown stock symbol: {$symbol}", 404);
}

$stockId = $stock['id'];

// Daily closes, oldest first — the backbone of the series.
$priceStmt = $pdo->prepare(
    'SELECT trade_date, close FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date ASC'
);
$priceStmt->execute(['id' => $stockId]);
$priceRows = $priceStmt->fetchAll(PDO::FETCH_ASSOC);

if ($priceRows === []) {
    api_error("No price history for {$symbol}", 404);
}

// Dividends keyed by ex-date for O(1) per-day lookup.
$divStmt = $pdo->prepare('SELECT ex_date, amount FROM stock_dividends WHERE stock_id = :id');
$divStmt->execute(['id' => $stockId]);
$dividendByDate = [];
foreach ($divStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dividendByDate[(string) $row['ex_date']] = (float) $row['amount'];
}

// Quarterly TTM EPS, oldest first, for forward-fill (latest report on or before each day).
$epsStmt = $pdo->prepare(
    'SELECT fiscal_quarter, eps_ttm FROM stock_quarterly_metrics WHERE stock_id = :id AND eps_ttm IS NOT NULL ORDER BY fiscal_quarter ASC'
);
$epsStmt->execute(['id' => $stockId]);
$epsPoints = $epsStmt->fetchAll(PDO::FETCH_ASSOC);

// Quarterly shares outstanding (stored as an absolute count); served in millions to match the
// old config-derived sharesOutstanding figure. Forward-filled the same way as EPS.
$sharesStmt = $pdo->prepare(
    'SELECT fiscal_quarter, shares_outstanding FROM stock_quarterly_market_cap WHERE stock_id = :id AND shares_outstanding IS NOT NULL ORDER BY fiscal_quarter ASC'
);
$sharesStmt->execute(['id' => $stockId]);
$sharesPoints = $sharesStmt->fetchAll(PDO::FETCH_ASSOC);

// Walk a sorted list of {date => value} points forward as `day` advances, returning the most
// recent value on or before `day` (null before the first point). Pointer is carried via $cursor.
$forwardFill = static function (array $points, string $dateKey, string $valueKey, string $day, int &$cursor): ?float {
    $count = count($points);
    while ($cursor + 1 < $count && (string) $points[$cursor + 1][$dateKey] <= $day) {
        $cursor++;
    }
    if ($cursor < 0 || $cursor >= $count) {
        return null;
    }
    if ((string) $points[$cursor][$dateKey] > $day) {
        return null;
    }

    return (float) $points[$cursor][$valueKey];
};

$epsCursor = -1;
$sharesCursor = -1;

$round = static fn (float $value, int $places): float => round($value, $places);

$historyByDate = [];
foreach ($priceRows as $row) {
    $day = (string) $row['trade_date'];
    $close = (float) $row['close'];

    $ttmEps = $forwardFill($epsPoints, 'fiscal_quarter', 'eps_ttm', $day, $epsCursor);

    $sharesRaw = $forwardFill($sharesPoints, 'fiscal_quarter', 'shares_outstanding', $day, $sharesCursor);
    // Convert absolute share count to millions, matching the simulator's historical units.
    $sharesOutstanding = $sharesRaw === null ? null : $round($sharesRaw / 1_000_000, 2);

    $dividendPerShare = $dividendByDate[$day] ?? 0;

    $historyByDate[$day] = [
        'close' => $close,
        'isPayoutDate' => array_key_exists($day, $dividendByDate),
        'dividendPerShare' => (float) $dividendPerShare,
        'ttmEps' => $ttmEps === null ? null : $round($ttmEps, 4),
        'peRatio' => ($ttmEps === null || $ttmEps == 0.0) ? null : $round($close / $ttmEps, 2),
        'sharesOutstanding' => $sharesOutstanding,
        'marketCap' => $sharesOutstanding === null ? null : $round($close * $sharesOutstanding, 0),
    ];
}

$dates = array_keys($historyByDate);

api_json([
    'stockCode' => $stock['symbol'],
    'source' => 'database',
    'range' => ['start' => $dates[0], 'end' => $dates[count($dates) - 1]],
    'historyByDate' => $historyByDate,
]);
