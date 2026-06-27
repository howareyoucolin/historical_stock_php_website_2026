<?php

declare(strict_types=1);

/**
 * Backfill daily prices for delisted/acquired stocks from researched price
 * anchors. For each stock we have a handful of approximate (date, close)
 * anchors plus a corporate-action fate. We reconstruct a full daily series by
 * interpolating between anchors with a Brownian bridge whose daily texture is
 * borrowed from a liquid proxy stock: the path lands exactly on every anchor
 * but wiggles realistically in between (so it is not a flat straight line).
 *
 * Real price rows are never overwritten (INSERT IGNORE), and stocks that
 * already have data are skipped entirely. Each stock's corporate action is also
 * upserted into stock_corporate_actions.
 *
 * Usage: php backfill_from_anchors.php [anchorsJsonPath] [endDate]
 */

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $jsonPath = (string) ($argv[1] ?? dirname(__DIR__) . '/raw/backfill-anchors.json');
    $endDate = trim((string) ($argv[2] ?? date('Y-m-d')));

    if (!is_file($jsonPath)) {
        throw new RuntimeException("Anchors file not found: {$jsonPath}");
    }

    $decoded = json_decode((string) file_get_contents($jsonPath), true);
    $stocks = is_array($decoded['stocks'] ?? null) ? $decoded['stocks'] : (is_array($decoded) ? $decoded : null);
    if (!is_array($stocks)) {
        throw new RuntimeException('Anchors JSON must be an array of stocks or {stocks:[...]}.');
    }

    $pdo = connect_pdo();

    [$calendar, $calIndex, $proxyClose] = load_proxy_calendar($pdo);
    if (count($calendar) < 2) {
        throw new RuntimeException('Not enough proxy calendar data.');
    }

    $symbolToId = fetch_symbol_id_map($pdo);

    $priceStmt = $pdo->prepare(
        'INSERT IGNORE INTO stock_daily_prices (stock_id, trade_date, close, adj_close, volume) '
        . 'VALUES (:stock_id, :trade_date, :close, :adj_close, :volume)'
    );
    $actionStmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_corporate_actions
    (stock_id, stock_code, action_date, type, cash_per_share, acquirer_stock_code, acquirer_stock_id, share_ratio, note)
VALUES
    (:stock_id, :stock_code, :action_date, :type, :cash_per_share, :acquirer_stock_code, :acquirer_stock_id, :share_ratio, :note)
ON DUPLICATE KEY UPDATE
    stock_id = VALUES(stock_id), cash_per_share = VALUES(cash_per_share),
    acquirer_stock_code = VALUES(acquirer_stock_code), acquirer_stock_id = VALUES(acquirer_stock_id),
    share_ratio = VALUES(share_ratio), note = VALUES(note), updated_at = CURRENT_TIMESTAMP
SQL
    );

    $stats = ['stocks' => 0, 'rows' => 0, 'skipped_have_data' => 0, 'skipped_no_id' => 0, 'skipped_thin' => 0, 'actions' => 0];

    foreach ($stocks as $stock) {
        if (!is_array($stock)) {
            continue;
        }
        $symbol = strtoupper(trim((string) ($stock['symbol'] ?? '')));
        if ($symbol === '') {
            continue;
        }

        $stockId = resolve_id($symbolToId, $symbol);
        if ($stockId === null) {
            $stats['skipped_no_id']++;
            echo "{$symbol}: no stocks row, skipped\n";
            continue;
        }
        if (stock_has_prices($pdo, $stockId)) {
            $stats['skipped_have_data']++;
            continue;
        }

        $fateType = (string) ($stock['fate_type'] ?? 'delisted');
        $fateDate = clamp_date((string) ($stock['fate_date'] ?? ''), $endDate);

        // Collect anchors, then append a terminal anchor implied by the fate.
        $anchors = normalize_anchors($stock['anchors'] ?? [], $endDate);
        $terminal = terminal_anchor($pdo, $symbolToId, $stock, $fateType, $fateDate, $endDate, $anchors);
        if ($terminal !== null) {
            $anchors[$terminal['date']] = $terminal['close'];
        }
        ksort($anchors);

        $series = build_series($anchors, $calendar, $calIndex, $proxyClose);
        if ($series === []) {
            $stats['skipped_thin']++;
            echo "{$symbol}: too few usable anchors, skipped\n";
            continue;
        }

        foreach ($series as $date => $close) {
            $priceStmt->execute([
                'stock_id' => $stockId,
                'trade_date' => $date,
                'close' => $close,
                'adj_close' => $close,
                'volume' => 1000000,
            ]);
        }
        $stats['rows'] += count($series);
        $stats['stocks']++;

        // Record the corporate action (active stocks get none).
        $actionType = map_action_type($fateType);
        if ($actionType !== null) {
            $acquirerCode = isset($stock['acquirer_symbol']) && $stock['acquirer_symbol'] !== null
                ? strtoupper(trim((string) $stock['acquirer_symbol'])) : null;
            $actionStmt->execute([
                'stock_id' => $stockId,
                'stock_code' => $symbol,
                'action_date' => $fateDate,
                'type' => $actionType,
                'cash_per_share' => isset($stock['cash_per_share']) && is_numeric($stock['cash_per_share']) ? (float) $stock['cash_per_share'] : null,
                'acquirer_stock_code' => $acquirerCode,
                'acquirer_stock_id' => $acquirerCode !== null ? resolve_id($symbolToId, $acquirerCode) : null,
                'share_ratio' => isset($stock['share_ratio']) && is_numeric($stock['share_ratio']) ? (float) $stock['share_ratio'] : null,
                'note' => trim((string) ($stock['note'] ?? '')) ?: null,
            ]);
            $stats['actions']++;
        }

        echo sprintf("%s (#%d) %s: %d rows [%s..%s]\n", $symbol, $stockId, $fateType, count($series), array_key_first($series), array_key_last($series));
    }

    echo "\n=== Backfill summary ===\n";
    foreach ($stats as $k => $v) {
        echo "{$k}: {$v}\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Backfill failed: ' . $e->getMessage() . "\n";
    exit(1);
}

// Most-complete stock becomes the market proxy / trading calendar.
function load_proxy_calendar(PDO $pdo): array
{
    $refId = (int) $pdo->query('SELECT stock_id FROM stock_daily_prices GROUP BY stock_id ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
    $stmt = $pdo->prepare('SELECT trade_date, close FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date');
    $stmt->execute(['id' => $refId]);

    $calendar = [];
    $calIndex = [];
    $proxyClose = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
        $d = (string) $row['trade_date'];
        $calendar[] = $d;
        $calIndex[$d] = $i;
        $proxyClose[$d] = (float) $row['close'];
    }

    return [$calendar, $calIndex, $proxyClose];
}

function fetch_symbol_id_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    return $map;
}

function resolve_id(array $map, string $code): ?int
{
    $code = strtoupper(trim($code));
    if (isset($map[$code])) {
        return $map[$code];
    }
    $resolved = strtoupper(resolve_price_symbol($code));
    return $resolved !== $code && isset($map[$resolved]) ? $map[$resolved] : null;
}

function stock_has_prices(PDO $pdo, int $stockId): bool
{
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare('SELECT 1 FROM stock_daily_prices WHERE stock_id = :id LIMIT 1');
    }
    $stmt->execute(['id' => $stockId]);

    return $stmt->fetchColumn() !== false;
}

// Clean (date => close) anchors: valid dates within [2001-01-01, endDate], positive prices.
function normalize_anchors(mixed $raw, string $endDate): array
{
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $a) {
        if (!is_array($a)) {
            continue;
        }
        $date = clamp_date((string) ($a['date'] ?? ''), $endDate);
        $close = (float) ($a['close'] ?? 0);
        if ($date === '' || $close <= 0) {
            continue;
        }
        $out[$date] = round($close, 4);
    }

    return $out;
}

function clamp_date(string $date, string $endDate): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    $d = date('Y-m-d', $ts);
    if ($d < '2001-01-01') {
        $d = '2001-01-01';
    }
    if ($d > $endDate) {
        $d = $endDate;
    }

    return $d;
}

// The implied final price at the fate date, by action type.
function terminal_anchor(PDO $pdo, array $symbolToId, array $stock, string $fateType, string $fateDate, string $endDate, array $anchors): ?array
{
    if ($fateDate === '') {
        return null;
    }
    $lastClose = $anchors === [] ? null : end($anchors);

    switch ($fateType) {
        case 'cash_buyout':
            $v = isset($stock['cash_per_share']) && is_numeric($stock['cash_per_share']) ? (float) $stock['cash_per_share'] : $lastClose;
            return $v !== null ? ['date' => $fateDate, 'close' => round((float) $v, 4)] : null;
        case 'equity_wipeout':
            return ['date' => $fateDate, 'close' => 0.05];
        case 'stock_swap':
            $acq = isset($stock['acquirer_symbol']) ? strtoupper(trim((string) $stock['acquirer_symbol'])) : '';
            $ratio = isset($stock['share_ratio']) && is_numeric($stock['share_ratio']) ? (float) $stock['share_ratio'] : null;
            $acqId = $acq !== '' ? resolve_id($symbolToId, $acq) : null;
            if ($acqId !== null && $ratio !== null) {
                $stmt = $pdo->prepare('SELECT close FROM stock_daily_prices WHERE stock_id = :id AND trade_date <= :d ORDER BY trade_date DESC LIMIT 1');
                $stmt->execute(['id' => $acqId, 'd' => $fateDate]);
                $c = $stmt->fetchColumn();
                if ($c !== false) {
                    return ['date' => $fateDate, 'close' => round((float) $c * $ratio, 4)];
                }
            }
            return $lastClose !== null ? ['date' => $fateDate, 'close' => round((float) $lastClose, 4)] : null;
        default: // delisted, renamed, active
            return $lastClose !== null ? ['date' => $fateDate, 'close' => round((float) $lastClose, 4)] : null;
    }
}

function map_action_type(string $fateType): ?string
{
    return match ($fateType) {
        'cash_buyout' => 'cash_buyout',
        'stock_swap' => 'stock_swap',
        'equity_wipeout' => 'equity_wipeout',
        'renamed' => 'renamed',
        'delisted' => 'delisted',
        default => null, // active
    };
}

// Brownian-bridge interpolation across the trading calendar, pinned to anchors.
function build_series(array $anchors, array $calendar, array $calIndex, array $proxyClose): array
{
    if (count($anchors) < 2) {
        return [];
    }

    // Snap each anchor date to the nearest calendar index; keep (index => price).
    $points = [];
    foreach ($anchors as $date => $close) {
        $idx = nearest_index($date, $calendar, $calIndex);
        if ($idx !== null) {
            $points[$idx] = $close; // later anchor on same index wins
        }
    }
    if (count($points) < 2) {
        return [];
    }
    ksort($points);
    $idxs = array_keys($points);

    $series = [];
    for ($p = 0; $p < count($idxs) - 1; $p++) {
        $iA = $idxs[$p];
        $iB = $idxs[$p + 1];
        $pA = $points[$iA];
        $pB = $points[$iB];
        $lnA = log($pA);
        $lnB = log($pB);
        $proxyA = log(max(1e-6, $proxyClose[$calendar[$iA]]));
        $proxyB = log(max(1e-6, $proxyClose[$calendar[$iB]]));
        $span = $iB - $iA;

        for ($i = $iA; $i < $iB; $i++) { // exclude iB; next segment's iA covers it
            $frac = $span > 0 ? ($i - $iA) / $span : 0.0;
            $proxyT = log(max(1e-6, $proxyClose[$calendar[$i]]));
            $noise = ($proxyT - $proxyA) - ($proxyB - $proxyA) * $frac; // bridge: 0 at both ends
            $ln = $lnA + ($lnB - $lnA) * $frac + $noise;
            $series[$calendar[$i]] = round(exp($ln), 4);
        }
    }
    // final anchor point
    $last = $idxs[count($idxs) - 1];
    $series[$calendar[$last]] = round($points[$last], 4);

    return $series;
}

// Nearest calendar index to a date (exact if it is a trading day).
function nearest_index(string $date, array $calendar, array $calIndex): ?int
{
    if (isset($calIndex[$date])) {
        return $calIndex[$date];
    }
    // binary search for closest
    $lo = 0;
    $hi = count($calendar) - 1;
    if ($hi < 0) {
        return null;
    }
    if ($date <= $calendar[0]) {
        return 0;
    }
    if ($date >= $calendar[$hi]) {
        return $hi;
    }
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ($calendar[$mid] === $date) {
            return $mid;
        }
        if ($calendar[$mid] < $date) {
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    // $hi is below, $lo is above; pick closer
    $below = max(0, $hi);
    $above = min(count($calendar) - 1, $lo);
    $dBelow = abs(strtotime($date) - strtotime($calendar[$below]));
    $dAbove = abs(strtotime($date) - strtotime($calendar[$above]));

    return $dBelow <= $dAbove ? $below : $above;
}
