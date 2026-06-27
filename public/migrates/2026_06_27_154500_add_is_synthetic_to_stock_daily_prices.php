<?php

return [
    'name' => '2026_06_27_154500_add_is_synthetic_to_stock_daily_prices',
    'up' => <<<'SQL'
ALTER TABLE stock_daily_prices
    ADD COLUMN is_synthetic TINYINT(1) NOT NULL DEFAULT 0 AFTER volume
SQL,
];
