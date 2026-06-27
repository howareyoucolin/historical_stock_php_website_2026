<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';
require_once dirname(__DIR__) . '/support/db.php';

return [
    'name' => 'stocks',
    'run' => static function (): void {
        $inputFile = dirname(__DIR__) . '/raw/sp500-members.json';

        if (!is_file($inputFile)) {
            throw new RuntimeException("Missing raw data file: {$inputFile}");
        }

        $raw = file_get_contents($inputFile);
        if ($raw === false) {
            throw new RuntimeException("Unable to read raw data file: {$inputFile}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Raw data file does not contain valid JSON.');
        }

        $symbols = [];

        foreach ($decoded as $key => $value) {
            if ($key === '_meta' || !is_array($value)) {
                continue;
            }

            foreach ($value as $ticker) {
                $symbol = normalize_stock_symbol((string) $ticker);
                if ($symbol === '') {
                    continue;
                }

                $canonicalSymbol = resolve_price_symbol($symbol);
                if ($canonicalSymbol === '') {
                    continue;
                }

                $symbols[$canonicalSymbol] = $canonicalSymbol;
            }
        }

        $pdo = connect_pdo();
        $existingSymbols = fetch_existing_symbols($pdo);
        $missingSymbols = array_diff_key($symbols, array_flip($existingSymbols));

        $insertStmt = $pdo->prepare('INSERT INTO stocks (symbol) VALUES (:symbol)');

        $inserted = 0;
        foreach ($missingSymbols as $symbol => $ignored) {
            $insertStmt->execute([
                'symbol' => $symbol,
            ]);
            $inserted += $insertStmt->rowCount();
        }

        echo "Processed " . count($symbols) . " symbols.\n";
        echo "Inserted {$inserted} new stock rows.\n";
        echo "Skipped " . (count($symbols) - $inserted) . " existing stock rows.\n";
    },
];

function fetch_existing_symbols(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT symbol FROM stocks');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map(static fn (mixed $symbol): string => (string) $symbol, $rows);
}
