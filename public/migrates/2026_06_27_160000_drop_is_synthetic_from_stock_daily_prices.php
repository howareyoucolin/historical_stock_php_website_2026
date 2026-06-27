<?php

return [
    'name' => '2026_06_27_160000_drop_is_synthetic_from_stock_daily_prices',
    'up' => <<<'SQL'
ALTER TABLE stock_daily_prices
    DROP COLUMN is_synthetic
SQL,
];
