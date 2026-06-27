<?php

return [
    'name' => '2026_06_27_000500_create_stock_daily_prices',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_daily_prices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    trade_date DATE NOT NULL,
    close DECIMAL(16,4) NOT NULL,
    adj_close DECIMAL(16,4) NULL,
    volume BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_daily_prices_stock_date (stock_id, trade_date),
    KEY idx_stock_daily_prices_trade_date (trade_date),
    CONSTRAINT fk_stock_daily_prices_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id)
        ON DELETE CASCADE
)
SQL,
];
