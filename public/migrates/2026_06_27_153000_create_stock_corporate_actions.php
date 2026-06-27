<?php

return [
    'name' => '2026_06_27_153000_create_stock_corporate_actions',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_corporate_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_id INT UNSIGNED NULL,
    stock_code VARCHAR(16) NOT NULL,
    action_date DATE NOT NULL,
    type VARCHAR(32) NOT NULL,
    cash_per_share DECIMAL(16,4) NULL,
    acquirer_stock_code VARCHAR(16) NULL,
    acquirer_stock_id INT UNSIGNED NULL,
    share_ratio DECIMAL(18,6) NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_corporate_actions_code_date_type (stock_code, action_date, type),
    KEY idx_stock_corporate_actions_stock_id (stock_id),
    KEY idx_stock_corporate_actions_action_date (action_date),
    CONSTRAINT fk_stock_corporate_actions_stock_id
        FOREIGN KEY (stock_id) REFERENCES stocks(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_stock_corporate_actions_acquirer_stock_id
        FOREIGN KEY (acquirer_stock_id) REFERENCES stocks(id)
        ON DELETE SET NULL
)
SQL,
];
