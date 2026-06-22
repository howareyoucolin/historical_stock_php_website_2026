<?php

return [
    'name' => '2026_06_22_121500_add_updated_by_to_stock_reports',
    'up' => <<<'SQL'
ALTER TABLE stock_reports
    ADD COLUMN updated_by INT UNSIGNED NULL AFTER values_log_path,
    ADD INDEX idx_stock_reports_updated_by (updated_by),
    ADD CONSTRAINT fk_stock_reports_updated_by
        FOREIGN KEY (updated_by) REFERENCES report_uploaders(id)
        ON DELETE SET NULL
SQL,
];
