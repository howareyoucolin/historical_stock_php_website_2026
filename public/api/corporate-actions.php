<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Serve corporate actions in the simulator's expected shape:
//   { actions: [ { stockCode, date, type, ...typeFields, note? } ] }
// The simulator models four types: cash_buyout, stock_swap, equity_wipeout, otc_continuation.
// The database also stores `renamed` and `delisted`, which are mapped here:
//   renamed  -> stock_swap into the new ticker at the recorded ratio (default 1:1)
//   delisted -> otc_continuation (shares kept at last close; trading stops). Only true
//               liquidations wipe equity to $0, and the data can't distinguish those, so we
//               take the non-destructive real-world outcome of a delisting.

$pdo = connect_pdo();

$rows = $pdo->query(
    <<<'SQL'
SELECT stock_code, action_date, type, cash_per_share, acquirer_stock_code, share_ratio, note
FROM stock_corporate_actions
ORDER BY action_date ASC, stock_code ASC
SQL
)->fetchAll(PDO::FETCH_ASSOC);

$actions = [];

foreach ($rows as $row) {
    $stockCode = strtoupper(trim((string) $row['stock_code']));
    $date = (string) $row['action_date'];
    $type = (string) $row['type'];
    $note = ($row['note'] !== null && trim((string) $row['note']) !== '') ? trim((string) $row['note']) : null;
    $acquirer = $row['acquirer_stock_code'] !== null ? strtoupper(trim((string) $row['acquirer_stock_code'])) : '';
    $cashPerShare = $row['cash_per_share'] !== null ? (float) $row['cash_per_share'] : null;
    $shareRatio = $row['share_ratio'] !== null ? (float) $row['share_ratio'] : null;

    if ($stockCode === '' || $date === '') {
        continue;
    }

    $action = ['stockCode' => $stockCode, 'date' => $date];
    if ($note !== null) {
        $action['note'] = $note;
    }

    switch ($type) {
        case 'cash_buyout':
            if ($cashPerShare === null || $cashPerShare < 0) {
                continue 2;
            }
            $action['type'] = 'cash_buyout';
            $action['cashPerShare'] = $cashPerShare;
            break;

        case 'stock_swap':
        case 'renamed':
            // A rename is economically a 1:1 swap into the successor ticker.
            if ($acquirer === '') {
                continue 2;
            }
            $action['type'] = 'stock_swap';
            $action['acquirerStockCode'] = $acquirer;
            $action['shareRatio'] = ($shareRatio !== null && $shareRatio > 0) ? $shareRatio : 1.0;
            if ($cashPerShare !== null && $cashPerShare >= 0) {
                $action['cashPerShare'] = $cashPerShare;
            }
            break;

        case 'equity_wipeout':
            $action['type'] = 'equity_wipeout';
            break;

        case 'delisted':
            $action['type'] = 'otc_continuation';
            break;

        default:
            continue 2;
    }

    $actions[] = $action;
}

api_json(['actions' => $actions]);
