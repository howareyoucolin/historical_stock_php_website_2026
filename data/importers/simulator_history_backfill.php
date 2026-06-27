<?php

declare(strict_types=1);

require_once __DIR__ . '/stock_daily_prices.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $symbols = array_slice($argv, 1);
    if ($symbols === []) {
        throw new RuntimeException('Provide one or more stock symbols to import from simulator history.');
    }

    $pdo = connect_pdo();
    $insertStmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_daily_prices (
    stock_id,
    trade_date,
    close,
    adj_close,
    volume
) VALUES (
    :stock_id,
    :trade_date,
    :close,
    :adj_close,
    :volume
)
ON DUPLICATE KEY UPDATE
    close = VALUES(close),
    adj_close = VALUES(adj_close),
    volume = VALUES(volume),
    updated_at = CURRENT_TIMESTAMP
SQL
    );

    foreach ($symbols as $rawSymbol) {
        $symbol = strtoupper(trim((string) $rawSymbol));
        if ($symbol === '') {
            continue;
        }

        $stock = find_stock($pdo, $symbol);
        if ($stock === null) {
            echo $symbol . " | missing from stocks\n";
            continue;
        }

        $marketDataDir = dirname(__DIR__, 3) . '/simulator/market-data/' . $stock['symbol'];
        $dataPath = $marketDataDir . '/data.json';
        $historyPath = $marketDataDir . '/history.json';

        $sourcePath = null;
        if (is_file($dataPath)) {
            $sourcePath = $dataPath;
        } elseif (is_file($historyPath)) {
            $sourcePath = $historyPath;
        }

        if ($sourcePath === null) {
            echo $symbol . " | simulator history missing\n";
            continue;
        }

        $raw = file_get_contents($sourcePath);
        if ($raw === false) {
            echo $symbol . " | failed to read simulator history\n";
            continue;
        }

        $decoded = json_decode($raw, true);
        $historyByDate = is_array($decoded['historyByDate'] ?? null) ? $decoded['historyByDate'] : null;
        if ($historyByDate === null) {
            echo $symbol . " | invalid simulator history format\n";
            continue;
        }

        ksort($historyByDate);

        $written = 0;
        $previousClose = null;

        foreach ($historyByDate as $tradeDate => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $close = $entry['close'] ?? null;
            if (!is_numeric($close)) {
                continue;
            }

            $closeValue = round((float) $close, 4);
            $volumeValue = estimate_synthetic_volume($entry, $closeValue, $previousClose);

            $insertStmt->execute([
                'stock_id' => $stock['id'],
                'trade_date' => (string) $tradeDate,
                'close' => $closeValue,
                'adj_close' => $closeValue,
                'volume' => $volumeValue,
            ]);

            $written += $insertStmt->rowCount();
            $previousClose = $closeValue;
        }

        echo $symbol . ' | simulator rows written=' . $written . "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Simulator backfill failed: ' . $e->getMessage() . "\n";
    exit(1);
}

function estimate_synthetic_volume(array $entry, float $close, ?float $previousClose): int
{
    $sharesOutstandingMillions = isset($entry['sharesOutstanding']) && is_numeric($entry['sharesOutstanding'])
        ? (float) $entry['sharesOutstanding']
        : null;

    if ($sharesOutstandingMillions !== null && $sharesOutstandingMillions > 0) {
        $sharesOutstanding = $sharesOutstandingMillions * 1000000;
    } else {
        $marketCapMillions = isset($entry['marketCap']) && is_numeric($entry['marketCap'])
            ? (float) $entry['marketCap']
            : 0.0;
        $sharesOutstanding = $close > 0 ? ($marketCapMillions * 1000000) / $close : 0.0;
    }

    if ($sharesOutstanding <= 0) {
        return 1000000;
    }

    $baseTurnover = 0.012;
    if ($sharesOutstanding >= 800000000) {
        $baseTurnover = 0.0085;
    } elseif ($sharesOutstanding >= 400000000) {
        $baseTurnover = 0.0100;
    } elseif ($sharesOutstanding >= 200000000) {
        $baseTurnover = 0.0125;
    } elseif ($sharesOutstanding >= 100000000) {
        $baseTurnover = 0.0150;
    } else {
        $baseTurnover = 0.0180;
    }

    $returnPct = 0.0;
    if ($previousClose !== null && $previousClose > 0) {
        $returnPct = abs(($close - $previousClose) / $previousClose);
    }

    $volatilityMultiplier = 1 + min(2.5, $returnPct * 18);
    $payoutMultiplier = !empty($entry['isPayoutDate']) ? 1.12 : 1.0;

    $estimated = (int) round($sharesOutstanding * $baseTurnover * $volatilityMultiplier * $payoutMultiplier);

    return max(50000, $estimated);
}
