<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/db.php';
require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';
require_once dirname(__DIR__) . '/support/corporate_actions.php';

return [
    'name' => 'stock_corporate_actions',
    'run' => static function (): void {
        $actions = load_corporate_actions();

        if ($actions === []) {
            echo "No corporate actions to seed.\n";

            return;
        }

        $pdo = connect_pdo();
        $symbolToId = fetch_stock_id_map($pdo);

        $insertStmt = $pdo->prepare(
            <<<'SQL'
INSERT INTO stock_corporate_actions (
    stock_id,
    stock_code,
    action_date,
    type,
    cash_per_share,
    acquirer_stock_code,
    acquirer_stock_id,
    share_ratio,
    note
) VALUES (
    :stock_id,
    :stock_code,
    :action_date,
    :type,
    :cash_per_share,
    :acquirer_stock_code,
    :acquirer_stock_id,
    :share_ratio,
    :note
)
ON DUPLICATE KEY UPDATE
    stock_id = VALUES(stock_id),
    cash_per_share = VALUES(cash_per_share),
    acquirer_stock_code = VALUES(acquirer_stock_code),
    acquirer_stock_id = VALUES(acquirer_stock_id),
    share_ratio = VALUES(share_ratio),
    note = VALUES(note),
    updated_at = CURRENT_TIMESTAMP
SQL
        );

        $written = 0;
        $unlinked = [];

        foreach ($actions as $action) {
            $stockCode = (string) $action['stock_code'];
            $stockId = resolve_stock_id($symbolToId, $stockCode);
            if ($stockId === null) {
                $unlinked[$stockCode] = true;
            }

            $acquirerCode = $action['acquirer_stock_code'] !== null ? (string) $action['acquirer_stock_code'] : null;
            $acquirerId = $acquirerCode !== null ? resolve_stock_id($symbolToId, $acquirerCode) : null;

            $insertStmt->execute([
                'stock_id' => $stockId,
                'stock_code' => $stockCode,
                'action_date' => (string) $action['action_date'],
                'type' => (string) $action['type'],
                'cash_per_share' => $action['cash_per_share'],
                'acquirer_stock_code' => $acquirerCode,
                'acquirer_stock_id' => $acquirerId,
                'share_ratio' => $action['share_ratio'],
                'note' => $action['note'],
            ]);

            $written++;
        }

        echo 'Seeded ' . count($actions) . " corporate action(s) ({$written} inserted/updated).\n";
        if ($unlinked !== []) {
            $codes = array_keys($unlinked);
            sort($codes, SORT_STRING);
            echo 'No matching stocks row for: ' . implode(', ', $codes) . " (stored with NULL stock_id).\n";
        }
    },
];

// Build a symbol -> id map for the whole stocks table.
function fetch_stock_id_map(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, symbol FROM stocks')->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[strtoupper((string) $row['symbol'])] = (int) $row['id'];
    }

    return $map;
}

// Resolve a corporate-action code to a stocks.id, honoring symbol remaps.
function resolve_stock_id(array $symbolToId, string $code): ?int
{
    $code = strtoupper(trim($code));
    if (isset($symbolToId[$code])) {
        return $symbolToId[$code];
    }

    $resolved = strtoupper(resolve_price_symbol($code));
    if ($resolved !== $code && isset($symbolToId[$resolved])) {
        return $symbolToId[$resolved];
    }

    return null;
}
