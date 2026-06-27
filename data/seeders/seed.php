<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$seedersDir = __DIR__;
$files = glob($seedersDir . '/*.php');

if ($files === false) {
    http_response_code(500);
    echo "Failed to read seeders directory.\n";
    exit(1);
}

$files = array_values(array_filter($files, static fn (string $file): bool => basename($file) !== 'seed.php'));
sort($files);

if ($files === []) {
    echo "No seeders found.\n";
    exit(0);
}

try {
    foreach ($files as $file) {
        $seeder = require $file;

        if (!is_array($seeder) || !isset($seeder['name'], $seeder['run']) || !is_callable($seeder['run'])) {
            throw new RuntimeException("Invalid seeder file: {$file}");
        }

        echo "Running {$seeder['name']}...\n";
        $seeder['run']();
        echo "Done {$seeder['name']}.\n";
    }

    echo "All seeders completed.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Seeder run failed: " . $e->getMessage() . "\n";
    exit(1);
}
