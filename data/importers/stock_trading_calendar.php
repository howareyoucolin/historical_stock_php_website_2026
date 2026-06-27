<?php

declare(strict_types=1);

/**
 * Populate trading_calendar with every distinct trading day present in
 * stock_daily_prices (the de-facto market calendar). Idempotent.
 *
 * Usage: php trading_calendar.php
 */

require_once dirname(__DIR__) . '/support/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = connect_pdo();
    $affected = $pdo->exec(
        'INSERT IGNORE INTO stock_trading_calendar (trade_date) SELECT DISTINCT trade_date FROM stock_daily_prices'
    );
    $total = (int) $pdo->query('SELECT COUNT(*) FROM stock_trading_calendar')->fetchColumn();
    echo "Trading calendar: {$affected} new day(s) added, {$total} total.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Trading calendar build failed: ' . $e->getMessage() . "\n";
    exit(1);
}
