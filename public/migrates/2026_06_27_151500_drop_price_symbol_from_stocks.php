<?php

return [
    'name' => '2026_06_27_151500_drop_price_symbol_from_stocks',
    'up' => <<<'SQL'
ALTER TABLE stocks
    DROP KEY idx_stocks_price_symbol,
    DROP COLUMN price_symbol
SQL,
];
