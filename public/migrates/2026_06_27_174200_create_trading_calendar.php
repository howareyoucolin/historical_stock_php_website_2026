<?php

return [
    'name' => '2026_06_27_174200_create_trading_calendar',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS trading_calendar (
    trade_date DATE NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL,
];
