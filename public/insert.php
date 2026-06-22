<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$batchDir = null;
$savedSuccessfully = false;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Use POST for this endpoint.', 405);
    }

    $providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_SECRET_KEY'] ?? ''));

    $config = load_config();
    $pdo = connect_pdo_from_config($config);
    $sessionFilesDir = null;
    $uploader = find_uploader_by_secret($pdo, $providedKey);

    $storageDir = storage_root_dir();
    ensure_directory($storageDir);

    $reportJson = load_input_file_contents($config, $sessionFilesDir, 'report_json_file');
    validate_json_string($reportJson, 'report_json_file');
    $strategyTitle = extract_strategy_title($reportJson);

    $insertStmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_reports (
    strategy_title,
    report_json,
    account_json_path,
    history_log_path,
    meta_json_path,
    values_log_path,
    updated_by
) VALUES (
    :strategy_title,
    :report_json,
    '',
    '',
    '',
    '',
    :updated_by
)
SQL
    );
    $insertStmt->execute([
        'strategy_title' => $strategyTitle,
        'report_json' => $reportJson,
        'updated_by' => (int) $uploader['id'],
    ]);

    $stockReportId = (int) $pdo->lastInsertId();
    $batchDir = $storageDir . '/' . $stockReportId;
    ensure_directory($batchDir);

    $accountJsonPath = materialize_input_file($config, $sessionFilesDir, 'account_json_file', $batchDir, 'account.json');
    $historyLogPath = materialize_input_file($config, $sessionFilesDir, 'history_log_file', $batchDir, 'history.log');
    $metaJsonPath = materialize_input_file($config, $sessionFilesDir, 'meta_json_file', $batchDir, 'meta.json');
    $valuesLogPath = materialize_input_file($config, $sessionFilesDir, 'values_log_file', $batchDir, 'values.log');

    validate_json_file($accountJsonPath, 'account_json');
    validate_json_file($metaJsonPath, 'meta_json');

    $updateStmt = $pdo->prepare(
        <<<'SQL'
UPDATE stock_reports
SET
    account_json_path = :account_json_path,
    history_log_path = :history_log_path,
    meta_json_path = :meta_json_path,
    values_log_path = :values_log_path
WHERE id = :id
SQL
    );

    $updateStmt->execute([
        'id' => $stockReportId,
        'account_json_path' => relative_storage_path($accountJsonPath),
        'history_log_path' => relative_storage_path($historyLogPath),
        'meta_json_path' => relative_storage_path($metaJsonPath),
        'values_log_path' => relative_storage_path($valuesLogPath),
    ]);

    http_response_code(201);
    $savedSuccessfully = true;
    echo json_encode([
        'ok' => true,
        'id' => $stockReportId,
        'message' => 'Stock report inserted successfully.',
        'updated_by' => [
            'id' => (int) $uploader['id'],
            'uploader' => (string) $uploader['uploader'],
        ],
        'files' => [
            'account_json_path' => relative_storage_path($accountJsonPath),
            'history_log_path' => relative_storage_path($historyLogPath),
            'meta_json_path' => relative_storage_path($metaJsonPath),
            'values_log_path' => relative_storage_path($valuesLogPath),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $statusCode = (int) $e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    if ($batchDir !== null && !$savedSuccessfully) {
        delete_directory($batchDir);
    }

    if (isset($pdo, $stockReportId) && $stockReportId > 0 && !$savedSuccessfully) {
        delete_stock_report($pdo, $stockReportId);
    }

    http_response_code($statusCode);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function require_post_string(string $key): string
{
    if (!array_key_exists($key, $_POST)) {
        throw new RuntimeException("Missing required field: {$key}", 400);
    }

    if (!is_string($_POST[$key])) {
        throw new RuntimeException("Field {$key} must be a string.", 400);
    }

    return $_POST[$key];
}

function has_uploaded_file(string $key): bool
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return false;
    }

    $error = (int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE);

    return $error !== UPLOAD_ERR_NO_FILE;
}

function uploaded_file_tmp_path(string $key): string
{
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        throw new RuntimeException("Missing required upload: {$key}", 400);
    }

    $error = (int) ($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed for {$key}.", 400);
    }

    $tmpName = (string) ($_FILES[$key]['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException("Uploaded file missing for {$key}.", 400);
    }

    return $tmpName;
}

function require_session_files_dir(array $config, ?string &$sessionFilesDir): string
{
    if ($sessionFilesDir !== null) {
        return $sessionFilesDir;
    }

    $sessionFilesDir = configured_session_files_dir($config);

    return $sessionFilesDir;
}

function load_input_file_contents(array $config, ?string &$sessionFilesDir, string $key): string
{
    if (has_uploaded_file($key)) {
        $contents = file_get_contents(uploaded_file_tmp_path($key));
        if ($contents === false) {
            throw new RuntimeException("Could not read uploaded file: {$key}", 500);
        }

        return $contents;
    }

    return load_source_file_contents(
        require_session_files_dir($config, $sessionFilesDir),
        require_post_string($key)
    );
}

function materialize_input_file(
    array $config,
    ?string &$sessionFilesDir,
    string $key,
    string $batchDir,
    string $targetName
): string {
    if (has_uploaded_file($key)) {
        $targetPath = $batchDir . '/' . $targetName;
        if (!move_uploaded_file(uploaded_file_tmp_path($key), $targetPath)) {
            throw new RuntimeException("Could not store uploaded file: {$key}", 500);
        }

        return $targetPath;
    }

    return copy_source_file(
        require_session_files_dir($config, $sessionFilesDir),
        require_post_string($key),
        $batchDir,
        $targetName
    );
}

function validate_json_string(string $value, string $field): void
{
    json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Field {$field} must contain valid JSON text.", 400);
    }
}

function extract_strategy_title(string $reportJson): string
{
    $decoded = json_decode($reportJson, true);
    if (!is_array($decoded)) {
        return 'Unknown strategy';
    }

    $strategy = is_array($decoded['strategy'] ?? null) ? $decoded['strategy'] : [];
    $summary = trim((string) ($strategy['summary'] ?? ''));
    $name = trim((string) ($strategy['name'] ?? ''));

    $title = $summary !== '' && $summary !== 'No strategy summary was provided.'
        ? $summary
        : $name;

    if ($title === '') {
        $title = 'Unknown strategy';
    }

    return mb_substr($title, 0, 255);
}

function copy_source_file(string $sourceDir, string $sourceName, string $batchDir, string $targetName): string
{
    assert_safe_file_name($sourceName);
    $sourcePath = $sourceDir . '/' . $sourceName;
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Source file not found: {$sourceName}", 400);
    }

    $targetPath = $batchDir . '/' . $targetName;
    if (!copy($sourcePath, $targetPath)) {
        throw new RuntimeException("Could not copy source file: {$sourceName}", 500);
    }

    return $targetPath;
}

function load_source_file_contents(string $sourceDir, string $sourceName): string
{
    assert_safe_file_name($sourceName);
    $sourcePath = $sourceDir . '/' . $sourceName;
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Source file not found: {$sourceName}", 400);
    }

    $contents = file_get_contents($sourcePath);
    if ($contents === false) {
        throw new RuntimeException("Could not read source file: {$sourceName}", 500);
    }

    return $contents;
}

function validate_json_file(string $path, string $field): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Could not read uploaded file for {$field}.", 500);
    }

    validate_json_string($contents, $field);
}

function storage_root_dir(): string
{
    return dirname(__DIR__) . '/storage/stock-reports';
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Could not create directory: {$path}", 500);
    }
}

function relative_storage_path(string $absolutePath): string
{
    $projectRoot = dirname(__DIR__);

    if (str_starts_with($absolutePath, $projectRoot . '/')) {
        return substr($absolutePath, strlen($projectRoot) + 1);
    }

    return $absolutePath;
}

function delete_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            delete_directory($itemPath);
            continue;
        }

        @unlink($itemPath);
    }

    @rmdir($path);
}

function assert_safe_file_name(string $fileName): void
{
    if ($fileName === '' || basename($fileName) !== $fileName || str_contains($fileName, '..')) {
        throw new RuntimeException("Invalid file name: {$fileName}", 400);
    }
}

function load_config(): array
{
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Missing config.php.', 500);
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.', 500);
    }

    return $config;
}

function configured_session_files_dir(array $config): string
{
    $paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
    $sessionFilesDir = (string) ($paths['session_files_dir'] ?? '');

    if ($sessionFilesDir === '') {
        throw new RuntimeException('Session files directory is not configured.', 500);
    }

    if (!is_dir($sessionFilesDir)) {
        throw new RuntimeException('Configured session files directory does not exist.', 500);
    }

    return rtrim($sessionFilesDir, '/');
}

function connect_pdo_from_config(array $config): PDO
{
    $db = is_array($config['db'] ?? null) ? $config['db'] : [];

    $host = (string) ($db['host'] ?? '');
    $database = (string) ($db['database'] ?? '');
    $username = (string) ($db['username'] ?? '');
    $password = (string) ($db['password'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Database config is incomplete.', 500);
    }

    $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function find_uploader_by_secret(PDO $pdo, string $secretKey): array
{
    if ($secretKey === '') {
        throw new RuntimeException('Missing secret key.', 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id, uploader FROM report_uploaders WHERE secret_key = :secret_key LIMIT 1'
    );
    $stmt->execute(['secret_key' => $secretKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        throw new RuntimeException('Invalid secret key.', 403);
    }

    return $row;
}

function delete_stock_report(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('DELETE FROM stock_reports WHERE id = :id');
    $stmt->execute(['id' => $id]);
}
