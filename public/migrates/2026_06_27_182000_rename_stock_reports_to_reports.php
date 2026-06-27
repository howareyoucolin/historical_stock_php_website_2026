<?php

return [
    'name' => '2026_06_27_182000_rename_stock_reports_to_reports',
    'up' => <<<'SQL'
RENAME TABLE stock_reports TO reports
SQL,
];
