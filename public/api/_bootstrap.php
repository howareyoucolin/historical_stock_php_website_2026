<?php

declare(strict_types=1);

// Shared bootstrap for the JSON API endpoints under public/api/. These endpoints back the
// stock simulator (which used to read local market-data/*.json files) by serving the same
// data straight from the database.

require_once dirname(__DIR__, 2) . '/data/support/db.php';
require_once dirname(__DIR__, 2) . '/data/support/stock_symbol_utils.php';

// Emit a JSON payload and stop. Sent with permissive CORS so the Next dev server (a different
// origin/port) can call these endpoints directly from server-side fetches.
function api_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Emit a JSON error envelope and stop.
function api_error(string $message, int $status = 400): never
{
    api_json(['error' => $message], $status);
}

// Read a required, validated stock symbol from the query string.
function api_require_symbol(): string
{
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? $_GET['code'] ?? '')));

    if ($symbol === '' || !preg_match('/^[A-Z0-9.\-]+$/', $symbol)) {
        api_error('A valid stock symbol is required.', 400);
    }

    return $symbol;
}

// Resolve a symbol to its stocks.id row, honoring the symbol remap config, or null when unknown.
function api_find_stock(PDO $pdo, string $symbol): ?array
{
    $resolved = resolve_price_symbol($symbol);

    $stmt = $pdo->prepare('SELECT id, symbol FROM stocks WHERE symbol = :symbol LIMIT 1');

    foreach (array_unique([$symbol, $resolved]) as $candidate) {
        $stmt->execute(['symbol' => $candidate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return ['id' => (int) $row['id'], 'symbol' => (string) $row['symbol']];
        }
    }

    return null;
}
