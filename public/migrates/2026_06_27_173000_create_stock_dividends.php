<?php

return [
    'name' => '2026_06_27_173000_create_stock_dividends',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_dividends (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NOT NULL,
    ex_date DATE NOT NULL,
    amount DECIMAL(16,6) NOT NULL,
    pay_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_dividends_stock_exdate (stock_id, ex_date),
    KEY idx_stock_dividends_ex_date (ex_date),
    CONSTRAINT fk_stock_dividends_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id) ON DELETE CASCADE
)
SQL,
];
