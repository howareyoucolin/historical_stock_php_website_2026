<?php

return [
    'name' => '2026_06_27_181000_prefix_market_data_tables',
    'up' => <<<'SQL'
RENAME TABLE
    index_membership TO stock_index_membership,
    market_index TO stock_market_index,
    trading_calendar TO stock_trading_calendar
SQL,
];
