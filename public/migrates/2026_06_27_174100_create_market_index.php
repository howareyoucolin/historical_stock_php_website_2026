<?php

return [
    'name' => '2026_06_27_174100_create_market_index',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS market_index (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    index_code VARCHAR(16) NOT NULL,
    trade_date DATE NOT NULL,
    level DECIMAL(18,6) NOT NULL,
    daily_return DECIMAL(14,8) NULL,
    constituents INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_market_index (index_code, trade_date),
    KEY idx_market_index_date (trade_date)
)
SQL,
];
