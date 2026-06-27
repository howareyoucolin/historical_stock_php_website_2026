<?php

return [
    'name' => '2026_06_27_173500_create_stock_quarterly_market_cap',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_quarterly_market_cap (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    fiscal_quarter DATE NOT NULL,
    close DECIMAL(16,4) NULL,
    shares_outstanding BIGINT UNSIGNED NULL,
    market_cap DECIMAL(20,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sqmc_stock_quarter (stock_id, fiscal_quarter),
    KEY idx_sqmc_quarter (fiscal_quarter),
    CONSTRAINT fk_sqmc_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
)
SQL,
];
