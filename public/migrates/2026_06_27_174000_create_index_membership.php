<?php

return [
    'name' => '2026_06_27_174000_create_index_membership',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS index_membership (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    index_code VARCHAR(16) NOT NULL,
    symbol VARCHAR(16) NOT NULL,
    stock_id INT UNSIGNED NULL,
    snapshot_year SMALLINT UNSIGNED NOT NULL,
    snapshot_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_index_membership (index_code, symbol, snapshot_year),
    KEY idx_index_membership_year (index_code, snapshot_year),
    KEY idx_index_membership_stock (stock_id),
    CONSTRAINT fk_index_membership_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE SET NULL
)
SQL,
];
