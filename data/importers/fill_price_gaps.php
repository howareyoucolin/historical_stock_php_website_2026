<?php

declare(strict_types=1);

/**
 * Fill internal date gaps in a stock's REAL price series. For each pair of
 * consecutive existing rows separated by missing trading days, interpolate the
 * intermediate days with a Brownian bridge (log-linear path pinned to the two
 * real endpoints, with daily texture borrowed from a liquid proxy stock).
 * Existing real rows are never modified (INSERT IGNORE); only the holes are
 * filled, so genuine data is preserved.
 *
 * Usage: php fill_price_gaps.php SYM1,SYM2,...   (default: the known gappy set)
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $arg = trim((string) ($argv[1] ?? 'HET,MAY,MEE,PDG,DDR,NCC'));
    $symbols = array_values(array_filter(array_map('trim', explode(',', strtoupper($arg)))));

    $pdo = connect_pdo();

    // proxy calendar from the most complete stock
    $refId = (int) $pdo->query('SELECT stock_id FROM stock_daily_prices GROUP BY stock_id ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();
    $refStmt = $pdo->prepare('SELECT trade_date, close FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date');
    $refStmt->execute(['id' => $refId]);
    $calendar = [];
    $calIndex = [];
    $proxy = [];
    foreach ($refStmt->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $d = (string) $r['trade_date'];
        $calendar[] = $d;
        $calIndex[$d] = $i;
        $proxy[$d] = (float) $r['close'];
    }

    $idStmt = $pdo->prepare('SELECT id FROM stocks WHERE symbol = :s LIMIT 1');
    $rowsStmt = $pdo->prepare('SELECT trade_date, close, adj_close FROM stock_daily_prices WHERE stock_id = :id ORDER BY trade_date');
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO stock_daily_prices (stock_id, trade_date, close, adj_close, volume) '
        . 'VALUES (:id, :d, :c, :a, 1000000)'
    );

    foreach ($symbols as $sym) {
        $idStmt->execute(['s' => $sym]);
        $stockId = $idStmt->fetchColumn();
        if ($stockId === false) {
            echo "{$sym}: not found\n";
            continue;
        }
        $stockId = (int) $stockId;

        $rowsStmt->execute(['id' => $stockId]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < 2) {
            echo "{$sym}: too few rows\n";
            continue;
        }

        // map each real row to a calendar index
        $pts = [];
        foreach ($rows as $r) {
            $idx = $calIndex[(string) $r['trade_date']] ?? null;
            if ($idx === null) {
                continue;
            }
            $pts[] = [$idx, (float) $r['close'], $r['adj_close'] === null ? (float) $r['close'] : (float) $r['adj_close']];
        }

        $filled = 0;
        for ($p = 0; $p < count($pts) - 1; $p++) {
            [$iA, $cA, $aA] = $pts[$p];
            [$iB, $cB, $aB] = $pts[$p + 1];
            if ($iB - $iA <= 1) {
                continue; // no gap
            }

            $lnCA = log(max(1e-6, $cA));
            $lnCB = log(max(1e-6, $cB));
            $lnAA = log(max(1e-6, $aA));
            $lnAB = log(max(1e-6, $aB));
            $pxA = log(max(1e-6, $proxy[$calendar[$iA]]));
            $pxB = log(max(1e-6, $proxy[$calendar[$iB]]));
            $span = $iB - $iA;

            for ($i = $iA + 1; $i < $iB; $i++) {
                $frac = ($i - $iA) / $span;
                $pxT = log(max(1e-6, $proxy[$calendar[$i]]));
                $noise = ($pxT - $pxA) - ($pxB - $pxA) * $frac; // bridge: 0 at both ends
                $close = exp($lnCA + ($lnCB - $lnCA) * $frac + $noise);
                $adj = exp($lnAA + ($lnAB - $lnAA) * $frac + $noise);
                $ins->execute([
                    'id' => $stockId,
                    'd' => $calendar[$i],
                    'c' => round($close, 4),
                    'a' => round($adj, 4),
                ]);
                $filled += $ins->rowCount();
            }
        }

        echo sprintf("%s (#%d): filled %d gap day(s)\n", $sym, $stockId, $filled);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Gap fill failed: ' . $e->getMessage() . "\n";
    exit(1);
}
