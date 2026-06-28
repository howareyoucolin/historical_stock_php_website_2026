<?php

return [
    'name' => '2026_06_28_120000_add_version_to_reports',
    'up' => <<<'SQL'
ALTER TABLE reports
    ADD COLUMN version INT NOT NULL DEFAULT 2 AFTER values_log_path
SQL,
];
