<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// The set of real NYSE trading days, ascending. The simulator uses this to step the simulation
// date only onto actual market days (it previously derived this from SPY's saved history).
$pdo = connect_pdo();

$dates = $pdo->query('SELECT trade_date FROM stock_trading_calendar ORDER BY trade_date ASC')
    ->fetchAll(PDO::FETCH_COLUMN);

api_json(['dates' => array_map('strval', $dates)]);
