<?php

return [
    'name' => '2026_06_27_141500_add_price_symbol_to_stocks',
    'up' => <<<'SQL'
ALTER TABLE stocks
    ADD COLUMN price_symbol VARCHAR(16) NULL AFTER symbol,
    ADD KEY idx_stocks_price_symbol (price_symbol)
SQL,
];
