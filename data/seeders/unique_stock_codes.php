<?php

declare(strict_types=1);

return [
    'name' => 'unique_stock_codes',
    'run' => static function (): void {
        $inputFile = dirname(__DIR__) . '/raw/sp500-members.json';
        $outputDir = dirname(__DIR__, 2) . '/public/data';
        $outputFile = $outputDir . '/stock-codes.json';

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

        $uniqueCodes = [];

        foreach ($decoded as $key => $value) {
            if ($key === '_meta' || !is_array($value)) {
                continue;
            }

            foreach ($value as $ticker) {
                $ticker = trim((string) $ticker);
                if ($ticker === '') {
                    continue;
                }

                $uniqueCodes[$ticker] = true;
            }
        }

        $codes = array_keys($uniqueCodes);
        sort($codes, SORT_STRING);

        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new RuntimeException("Unable to create output directory: {$outputDir}");
        }

        $json = json_encode($codes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode stock codes JSON.');
        }

        if (file_put_contents($outputFile, $json . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write output file: {$outputFile}");
        }

        echo 'Wrote ' . count($codes) . " stock codes to {$outputFile}.\n";
    },
];
