<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Serve a stock's static profile (classification metadata) straight from the database. This is
// non-time-series data — company name, sector, industry, description — so it carries no hindsight
// risk and replaces the old curated stock-profiles.json (which covered only part of the universe
// and hand-coded forward-looking listing notes).

$symbol = api_require_symbol();
$pdo = connect_pdo();

$stmt = $pdo->prepare(
    'SELECT symbol, company_name, sector, industry, description FROM stocks WHERE symbol = :symbol LIMIT 1'
);
$stmt->execute(['symbol' => $symbol]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    api_error("Unknown stock symbol: {$symbol}", 404);
}

api_json([
    'stockCode' => (string) $row['symbol'],
    'companyName' => $row['company_name'] !== null ? (string) $row['company_name'] : null,
    'sector' => $row['sector'] !== null ? (string) $row['sector'] : null,
    'industry' => $row['industry'] !== null ? (string) $row['industry'] : null,
    'description' => $row['description'] !== null ? (string) $row['description'] : null,
]);
