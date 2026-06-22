<?php

return [
    'name' => '2026_06_21_195700_create_stock_reports',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_reports (
    account_json JSON,
    history_log TEXT,
    meta_json JSON,
    report_json JSON,
    values_log TEXT
)
SQL,
];
