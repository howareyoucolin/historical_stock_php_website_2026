<?php

return [
    'name' => '2026_06_21_195700_create_stock_reports',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_json LONGTEXT NOT NULL,
    account_json_path VARCHAR(255) NOT NULL,
    history_log_path VARCHAR(255) NOT NULL,
    meta_json_path VARCHAR(255) NOT NULL,
    values_log_path VARCHAR(255) NOT NULL
)
SQL,
];
