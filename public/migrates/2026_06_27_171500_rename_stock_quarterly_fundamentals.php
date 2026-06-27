<?php

return [
    'name' => '2026_06_27_171500_rename_stock_quarterly_fundamentals',
    'up' => <<<'SQL'
RENAME TABLE stock_quarterly_fundamentals TO stock_quarterly_metrics
SQL,
];
