<?php

return [
    'name' => '2026_06_27_174300_create_stock_splits',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_splits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    split_date DATE NOT NULL,
    ratio DECIMAL(12,6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_splits_stock_date (stock_id, split_date),
    KEY idx_stock_splits_date (split_date),
    CONSTRAINT fk_stock_splits_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
)
SQL,
];
