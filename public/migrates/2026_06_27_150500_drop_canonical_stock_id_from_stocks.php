<?php

return [
    'name' => '2026_06_27_150500_drop_canonical_stock_id_from_stocks',
    'up' => <<<'SQL'
ALTER TABLE stocks
    DROP FOREIGN KEY fk_stocks_canonical_stock_id,
    DROP KEY idx_stocks_canonical_stock_id,
    DROP COLUMN canonical_stock_id
SQL,
];
