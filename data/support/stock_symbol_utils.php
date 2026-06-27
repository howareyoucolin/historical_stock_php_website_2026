<?php

declare(strict_types=1);

function load_stock_symbol_remaps(): array
{
    static $remaps = null;

    if (is_array($remaps)) {
        return $remaps;
    }

    $path = dirname(__DIR__) . '/config/stock-symbol-remaps.json';
    if (!is_file($path)) {
        $remaps = [];

        return $remaps;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Unable to read stock symbol remap file: {$path}");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON in stock symbol remap file: {$path}");
    }

    $normalized = [];

    foreach ($decoded as $from => $to) {
        $fromSymbol = normalize_stock_symbol((string) $from);
        $toSymbol = normalize_stock_symbol((string) $to);

        if ($fromSymbol === '' || $toSymbol === '') {
            continue;
        }

        $normalized[$fromSymbol] = $toSymbol;
    }

    $remaps = $normalized;

    return $remaps;
}

function normalize_stock_symbol(string $symbol): string
{
    $symbol = trim($symbol);
    $symbol = preg_replace('/\s+\(.*$/', '', $symbol) ?? $symbol;

    return trim($symbol);
}

function resolve_price_symbol(string $symbol): string
{
    $symbol = normalize_stock_symbol($symbol);
    $remaps = load_stock_symbol_remaps();

    return $remaps[$symbol] ?? $symbol;
}
