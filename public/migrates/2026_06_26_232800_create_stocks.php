<?php

return [
    'name' => '2026_06_26_232800_create_stocks',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(16) NOT NULL,
    company_name VARCHAR(255) NULL,
    sector VARCHAR(128) NULL,
    industry VARCHAR(128) NULL,
    description VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stocks_symbol (symbol)
)
SQL,
];
