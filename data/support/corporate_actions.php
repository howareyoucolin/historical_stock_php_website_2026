<?php

declare(strict_types=1);

// PHP mirror of the simulator's corporate-actions engine
// (simulator/app/actions/account/corporate-actions.ts). Loads and validates the
// corporate-action rules so seeders and checkers can assume a clean shape.

if (!defined('CORPORATE_ACTIONS_CONFIG_PATH')) {
    define('CORPORATE_ACTIONS_CONFIG_PATH', dirname(__DIR__) . '/config/stock-corporate-actions.json');
}

if (!function_exists('corporate_action_types')) {
    /**
     * @return string[] the supported corporate-action type identifiers.
     */
    function corporate_action_types(): array
    {
        return ['cash_buyout', 'stock_swap', 'equity_wipeout', 'otc_continuation', 'delisted', 'renamed'];
    }
}

if (!function_exists('normalize_corporate_stock_code')) {
    // Match the simulator's normalizeStockCode: trim + uppercase.
    function normalize_corporate_stock_code(string $stockCode): string
    {
        return strtoupper(trim($stockCode));
    }
}

if (!function_exists('parse_corporate_action')) {
    /**
     * Validate a single corporate-action row and return it in a normalized shape.
     * Throws on malformed input so callers can assume valid data downstream.
     *
     * @return array{stock_code:string,action_date:string,type:string,cash_per_share:?float,acquirer_stock_code:?string,share_ratio:?float,note:?string}
     */
    function parse_corporate_action(mixed $entry, int $index): array
    {
        if (!is_array($entry)) {
            throw new RuntimeException("Invalid corporate action at index {$index}.");
        }

        $stockCode = normalize_corporate_stock_code((string) ($entry['stockCode'] ?? ''));
        $date = trim((string) ($entry['date'] ?? ''));
        $type = trim((string) ($entry['type'] ?? ''));
        $noteRaw = isset($entry['note']) ? trim((string) $entry['note']) : '';
        $note = $noteRaw === '' ? null : $noteRaw;

        if ($stockCode === '' || $date === '' || $type === '') {
            throw new RuntimeException("Corporate action at index {$index} is missing stockCode, date, or type.");
        }

        $normalized = [
            'stock_code' => $stockCode,
            'action_date' => $date,
            'type' => $type,
            'cash_per_share' => null,
            'acquirer_stock_code' => null,
            'share_ratio' => null,
            'note' => $note,
        ];

        switch ($type) {
            case 'cash_buyout':
                $cashPerShare = filter_var($entry['cashPerShare'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($cashPerShare === false || $cashPerShare < 0) {
                    throw new RuntimeException("Cash buyout for {$stockCode} must include a non-negative cashPerShare.");
                }
                $normalized['cash_per_share'] = (float) $cashPerShare;

                return $normalized;

            case 'stock_swap':
                $acquirer = normalize_corporate_stock_code((string) ($entry['acquirerStockCode'] ?? ''));
                if ($acquirer === '') {
                    throw new RuntimeException("Stock swap for {$stockCode} must include acquirerStockCode.");
                }

                $shareRatio = filter_var($entry['shareRatio'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($shareRatio === false || $shareRatio <= 0) {
                    throw new RuntimeException("Stock swap for {$stockCode} must include a positive shareRatio.");
                }

                $normalized['acquirer_stock_code'] = $acquirer;
                $normalized['share_ratio'] = (float) $shareRatio;

                if (array_key_exists('cashPerShare', $entry) && $entry['cashPerShare'] !== null) {
                    $cashPerShare = filter_var($entry['cashPerShare'], FILTER_VALIDATE_FLOAT);
                    if ($cashPerShare === false || $cashPerShare < 0) {
                        throw new RuntimeException("Stock swap for {$stockCode} has an invalid cashPerShare.");
                    }
                    $normalized['cash_per_share'] = (float) $cashPerShare;
                }

                return $normalized;

            case 'equity_wipeout':
            case 'otc_continuation':
            case 'delisted':
                return $normalized;

            default:
                throw new RuntimeException("Unsupported corporate action type for {$stockCode}: {$type}");
        }
    }
}

if (!function_exists('load_corporate_actions')) {
    /**
     * Read and validate the corporate-action config.
     *
     * @return array<int, array<string, mixed>> normalized corporate actions.
     */
    function load_corporate_actions(?string $path = null): array
    {
        $configPath = $path ?? CORPORATE_ACTIONS_CONFIG_PATH;

        if (!is_file($configPath)) {
            return [];
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            throw new RuntimeException("Unable to read corporate actions config: {$configPath}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Corporate actions config is not valid JSON.');
        }

        $actions = $decoded['actions'] ?? null;
        if (!is_array($actions)) {
            throw new RuntimeException('Corporate actions config must contain an actions array.');
        }

        $parsed = [];
        foreach ($actions as $index => $entry) {
            $parsed[] = parse_corporate_action($entry, (int) $index);
        }

        return $parsed;
    }
}
