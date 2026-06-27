<?php

return [
    'name' => '2026_06_27_143500_add_canonical_stock_id_to_stocks',
    'up' => <<<'SQL'
ALTER TABLE stocks
    ADD COLUMN canonical_stock_id INT UNSIGNED NULL AFTER price_symbol,
    ADD KEY idx_stocks_canonical_stock_id (canonical_stock_id),
    ADD CONSTRAINT fk_stocks_canonical_stock_id
        FOREIGN KEY (canonical_stock_id) REFERENCES stocks(id)
        ON DELETE SET NULL
SQL,
];
