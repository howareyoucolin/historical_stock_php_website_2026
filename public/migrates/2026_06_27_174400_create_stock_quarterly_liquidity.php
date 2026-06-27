<?php

return [
    'name' => '2026_06_27_174400_create_stock_quarterly_liquidity',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_quarterly_liquidity (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    fiscal_quarter DATE NOT NULL,
    realized_vol DECIMAL(12,6) NULL,
    avg_daily_volume BIGINT UNSIGNED NULL,
    trading_days INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sql_stock_quarter (stock_id, fiscal_quarter),
    KEY idx_sql_quarter (fiscal_quarter),
    CONSTRAINT fk_sql_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
)
SQL,
];
