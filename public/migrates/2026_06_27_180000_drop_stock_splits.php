<?php

return [
    'name' => '2026_06_27_180000_drop_stock_splits',
    'up' => <<<'SQL'
DROP TABLE IF EXISTS stock_splits
SQL,
];
