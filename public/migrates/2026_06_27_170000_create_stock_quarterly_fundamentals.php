<?php

return [
    'name' => '2026_06_27_170000_create_stock_quarterly_fundamentals',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_quarterly_fundamentals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    fiscal_quarter DATE NOT NULL,
    close DECIMAL(16,4) NULL,
    eps_ttm DECIMAL(16,4) NULL,
    eps_growth DECIMAL(12,4) NULL,
    forward_eps DECIMAL(16,4) NULL,
    pe DECIMAL(16,4) NULL,
    forward_pe DECIMAL(16,4) NULL,
    peg DECIMAL(12,4) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sqf_stock_quarter (stock_id, fiscal_quarter),
    KEY idx_sqf_quarter (fiscal_quarter),
    CONSTRAINT fk_sqf_stock_id FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
)
SQL,
];
