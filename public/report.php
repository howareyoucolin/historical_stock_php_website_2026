<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$pdo = connect_pdo($config);
$reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($reportId === false || $reportId === null || $reportId < 1) {
    http_response_code(400);
    echo 'Invalid report id.';
    exit;
}

$stmt = $pdo->prepare(
    <<<'SQL'
SELECT reports.id,
       reports.report_json,
       reports.created_at,
       reports.account_json_path,
       reports.history_log_path,
       reports.meta_json_path,
       reports.values_log_path,
       reports.version,
       report_uploaders.uploader AS updated_by_name
FROM reports
LEFT JOIN report_uploaders
  ON report_uploaders.id = reports.updated_by
WHERE reports.id = :id
LIMIT 1
SQL
);
$stmt->execute(['id' => $reportId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    http_response_code(404);
    echo 'Report not found.';
    exit;
}

$report = json_decode((string) $row['report_json'], true);
$isValidJson = is_array($report);
$projectRoot = dirname(__DIR__);
$historyFileContent = read_stored_file($projectRoot, (string) ($row['history_log_path'] ?? ''));
$valuesFileContent = read_stored_file($projectRoot, (string) ($row['values_log_path'] ?? ''));
$historyEntries = parse_history_entries($historyFileContent);
$historySummary = build_history_summary($historyEntries);
$historyTypes = list_history_types($historyEntries);
$valueSnapshots = trim_leading_zero_value_snapshots(parse_value_snapshots($valuesFileContent));
$valuesSummary = build_values_summary($valueSnapshots);

function connect_pdo(array $config): PDO
{
    $db = is_array($config['db'] ?? null) ? $config['db'] : [];
    $host = (string) ($db['host'] ?? '');
    $database = (string) ($db['database'] ?? '');
    $username = (string) ($db['username'] ?? '');
    $password = (string) ($db['password'] ?? '');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Database config is incomplete.');
    }

    $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function section_value(array $source, string $key, string $fallback = '-'): string
{
    $value = $source[$key] ?? $fallback;

    if (is_array($value)) {
        return $fallback;
    }

    return (string) $value;
}

function render_key_values(array $values): void
{
    echo '<dl class="stats">';
    foreach ($values as $label => $value) {
        $displayValue = format_display_value($value);
        $valueClass = metric_value_class($label, $value);
        echo '<div><dt>' . h($label) . '</dt><dd class="' . h($valueClass) . '">' . h($displayValue) . '</dd></div>';
    }
    echo '</dl>';
}

function render_scored_items(array $items): void
{
    if ($items === []) {
        echo '<p class="muted">None.</p>';
        return;
    }

    echo '<ul class="list">';
    foreach ($items as $item) {
        $text = is_array($item) ? (string) ($item['text'] ?? '') : (string) $item;
        $score = is_array($item) && array_key_exists('score', $item) ? ' (' . $item['score'] . ')' : '';
        echo '<li>' . h($text . $score) . '</li>';
    }
    echo '</ul>';
}

function scored_item_text(mixed $item): string
{
    if (is_array($item)) {
        return (string) ($item['text'] ?? '');
    }

    return (string) $item;
}

function format_display_value(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        $numeric = (float) $value;
        $decimals = floor($numeric) === $numeric ? 0 : 2;
        return number_format($numeric, $decimals, '.', ',');
    }

    if (!is_string($value)) {
        return (string) $value;
    }

    $trimmed = trim($value);
    if ($trimmed === '' || !is_numeric($trimmed)) {
        return $value;
    }

    $number = (float) $trimmed;
    $decimals = str_contains($trimmed, '.') ? strlen(rtrim(substr(strrchr($trimmed, '.'), 1), '0')) : 0;

    return number_format($number, $decimals, '.', ',');
}

function metric_value_class(string $label, mixed $value): string
{
    $labelLower = strtolower($label);
    $numericValue = null;

    if (is_int($value) || is_float($value)) {
        $numericValue = (float) $value;
    } elseif (is_string($value) && is_numeric(trim($value))) {
        $numericValue = (float) trim($value);
    }

    if ($numericValue === null) {
        return 'value-neutral';
    }

    $looksLikeChangeMetric = str_contains($labelLower, 'gain')
        || str_contains($labelLower, 'loss')
        || str_contains($labelLower, 'return')
        || str_contains($labelLower, 'drawdown')
        || str_contains($labelLower, 'tax')
        || str_contains($labelLower, 'cash')
        || str_contains($labelLower, 'change');

    if (!$looksLikeChangeMetric) {
        return 'value-neutral';
    }

    if ($numericValue < 0) {
        return 'value-negative';
    }

    if ($numericValue > 0 && !str_contains($labelLower, 'tax')) {
        return 'value-positive';
    }

    if ($numericValue > 0 && str_contains($labelLower, 'tax')) {
        return 'value-negative';
    }

    return 'value-neutral';
}

function format_timeline_date(?string $date): string
{
    if ($date === null || trim($date) === '') {
        return '-';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    return date('M j, Y', $timestamp);
}

function format_money_value(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return number_format((float) $value, 2, '.', ',');
    }

    if (is_string($value) && is_numeric(trim($value))) {
        return number_format((float) trim($value), 2, '.', ',');
    }

    return (string) $value;
}

function format_signed_money_value(?float $value): string
{
    if ($value === null) {
        return '-';
    }

    $sign = $value >= 0 ? '+' : '-';

    return $sign . number_format(abs($value), 2, '.', ',');
}

function format_signed_percent_value(?float $value): string
{
    if ($value === null) {
        return '-';
    }

    $sign = $value >= 0 ? '+' : '-';

    return $sign . number_format(abs($value), 2, '.', ',') . '%';
}

function tone_class(?float $value): string
{
    return ($value ?? 0.0) >= 0 ? 'pos' : 'neg';
}

function read_stored_file(string $projectRoot, string $relativePath): string
{
    if ($relativePath === '') {
        return 'No file path stored.';
    }

    $absolutePath = $projectRoot . '/' . ltrim($relativePath, '/');
    $realProjectRoot = realpath($projectRoot);
    $realFilePath = realpath($absolutePath);

    if ($realProjectRoot === false || $realFilePath === false || !str_starts_with($realFilePath, $realProjectRoot . DIRECTORY_SEPARATOR)) {
        return 'Stored file could not be resolved safely.';
    }

    $contents = file_get_contents($realFilePath);
    if ($contents === false) {
        return 'Stored file could not be read.';
    }

    return $contents;
}

function parse_history_entries(string $contents): array
{
    $trimmed = trim($contents);
    if ($trimmed === '' || str_starts_with($trimmed, 'Stored file')) {
        return [];
    }

    $entries = [];

    foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $noteMarker = ' note=';
        $markerIndex = strpos($line, $noteMarker);
        $head = $markerIndex === false ? $line : substr($line, 0, $markerIndex);
        $note = '';

        if ($markerIndex !== false) {
            $rawNote = substr($line, $markerIndex + strlen($noteMarker));
            $decodedNote = json_decode($rawNote, true);
            $note = is_string($decodedNote) ? $decodedNote : trim($rawNote, '"');
        }

        $parts = preg_split('/\s+/', $head) ?: [];
        if (count($parts) < 2) {
            continue;
        }

        $entry = [
            'timestamp' => array_shift($parts),
            'type' => array_shift($parts),
            'stock' => '',
            'quantity' => '',
            'price' => '',
            'acquired' => '',
            'cash' => '',
            'sim' => '',
            'term' => '',
            'note' => $note,
        ];

        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $rawValue] = explode('=', $part, 2);
            $value = $rawValue;

            if ($key === 'note') {
                $decoded = json_decode($rawValue, true);
                $value = is_string($decoded) ? $decoded : trim($rawValue, '"');
            }

            if ($key === 'stock') {
                $entry['stock'] = $value;
            } elseif ($key === 'qty') {
                $entry['quantity'] = $value;
            } elseif ($key === 'price') {
                $entry['price'] = $value;
            } elseif ($key === 'acquired') {
                $entry['acquired'] = $value;
            } elseif ($key === 'cash') {
                $entry['cash'] = $value;
            } elseif ($key === 'sim') {
                $entry['sim'] = $value;
            } elseif ($key === 'term') {
                $entry['term'] = $value;
            }
        }

        $entries[] = $entry;
    }

    return $entries;
}

function build_history_summary(array $entries): array
{
    $counts = [];

    foreach ($entries as $entry) {
        $type = (string) ($entry['type'] ?? 'UNKNOWN');
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }

    ksort($counts);

    return [
        'total' => count($entries),
        'counts' => $counts,
        'recent' => array_reverse($entries),
    ];
}

function list_history_types(array $entries): array
{
    $seen = [];

    foreach ($entries as $entry) {
        $type = (string) ($entry['type'] ?? '');
        if ($type !== '' && !in_array($type, $seen, true)) {
            $seen[] = $type;
        }
    }

    return $seen;
}

function parse_value_snapshots(string $contents): array
{
    $trimmed = trim($contents);
    if ($trimmed === '' || str_starts_with($trimmed, 'Stored file')) {
        return [];
    }

    $latestByDate = [];
    foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        [$date, $rawValue] = preg_split('/\s+/', $line, 2) ?: [null, null];
        if (!$date || $rawValue === null || !is_numeric($rawValue)) {
            continue;
        }

        $latestByDate[$date] = (float) $rawValue;
    }

    ksort($latestByDate);
    $snapshots = [];
    foreach ($latestByDate as $date => $value) {
        $snapshots[] = ['date' => $date, 'value' => $value];
    }

    return $snapshots;
}

// Drop the leading unfunded period (total value 0 before the first deposit) so the value graph and
// summary start when the portfolio first holds value. Mirrors the simulator's trimLeadingZeroValues:
// a sim started after the account's default date records zero-value days that are just noise. Interior
// zeros are kept; an all-zero series collapses to empty.
function trim_leading_zero_value_snapshots(array $snapshots): array
{
    foreach ($snapshots as $index => $snapshot) {
        if ((float) $snapshot['value'] !== 0.0) {
            return array_values(array_slice($snapshots, $index));
        }
    }

    return [];
}

function build_values_summary(array $snapshots): array
{
    if ($snapshots === []) {
        return [
            'count' => 0,
            'first' => null,
            'last' => null,
            'high' => null,
            'low' => null,
            'change' => null,
        ];
    }

    $values = array_column($snapshots, 'value');
    $first = $snapshots[0];
    $last = $snapshots[count($snapshots) - 1];
    $highValue = max($values);
    $lowValue = min($values);
    $high = null;
    $low = null;

    foreach ($snapshots as $snapshot) {
        if ($high === null && (float) $snapshot['value'] === (float) $highValue) {
            $high = $snapshot;
        }
        if ($low === null && (float) $snapshot['value'] === (float) $lowValue) {
            $low = $snapshot;
        }
    }

    return [
        'count' => count($snapshots),
        'first' => $first,
        'last' => $last,
        'high' => $high,
        'low' => $low,
        'change' => (float) $last['value'] - (float) $first['value'],
        'recent' => array_slice(array_reverse($snapshots), 0, 180),
    ];
}

$reportMeta = is_array($report) ? $report : [];
$reportMetaStrategy = is_array($reportMeta['strategy'] ?? null) ? $reportMeta['strategy'] : [];
$reportMetaObjective = is_array($reportMeta['objective'] ?? null) ? $reportMeta['objective'] : [];
$reportMetaSimulation = is_array($reportMeta['simulation'] ?? null) ? $reportMeta['simulation'] : [];
$reportMetaPortfolio = is_array($reportMeta['portfolioSummary'] ?? null) ? $reportMeta['portfolioSummary'] : [];
$reportPageTitle = trim((string) ($reportMetaStrategy['name'] ?? '')) !== ''
    ? (string) $reportMetaStrategy['name'] . ' | Stock Simulation Report'
    : 'Stock Simulation Report #' . $row['id'];
$reportEndingValue = isset($reportMetaPortfolio['currentTotal']) ? format_money_value($reportMetaPortfolio['currentTotal']) : 'N/A';
$reportReturn = isset($reportMetaPortfolio['totalReturnPct']) ? number_format((float) $reportMetaPortfolio['totalReturnPct'], 2, '.', ',') . '%' : 'N/A';
$reportEndDate = (string) ($reportMetaSimulation['simEndDate'] ?? 'latest date');
$reportPageDescription = 'Detailed stock simulation report with portfolio performance, risk metrics, taxes, strategy notes, and report-date positions. End date ' . $reportEndDate . ', ending value ' . $reportEndingValue . ', total return ' . $reportReturn . '.';
$reportPageKeywords = 'stock simulation report, portfolio performance, investment report, trading strategy report, positions table, portfolio return, risk metrics';
$reportHeaderTitle = trim((string) ($reportMetaStrategy['name'] ?? '')) !== ''
    ? (string) $reportMetaStrategy['name']
    : ('Report #' . $row['id']);
$reportHeaderSummary = trim((string) ($report['takeaways']['summary'] ?? '')) !== ''
    ? (string) $report['takeaways']['summary']
    : 'Detailed simulation report with summary, activity log, and value timeline.';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($reportPageTitle) ?></title>
  <meta name="description" content="<?= h($reportPageDescription) ?>">
  <meta name="keywords" content="<?= h($reportPageKeywords) ?>">
  <style>
    :root {
      --bg: #f7f9fc;
      --panel: #ffffff;
      --panel-border: #dfe3eb;
      --text: #202124;
      --muted: #5f6368;
      --subtle: #80868b;
      --blue: #1a73e8;
      --blue-soft: #e8f0fe;
      --shadow: 0 1px 2px rgba(60, 64, 67, 0.15);
      --sim-surface: #fffaf5;
      --sim-surface-strong: #fffdf9;
      --sim-border: #eadfd2;
      --sim-border-strong: #d8c7b6;
      --sim-muted: #7a6a5d;
      --sim-pos: #2f8f57;
      --sim-neg: #c0392b;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: Arial, Helvetica, sans-serif;
    }
    .pageBanner {
      background:
        radial-gradient(circle at top left, rgba(26, 115, 232, 0.18), transparent 34%),
        linear-gradient(135deg, #eef4ff 0%, #f8fbff 44%, #f4f7fc 100%);
      border-bottom: 1px solid rgba(26, 115, 232, 0.10);
      box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.75);
    }
    .pageBannerInner {
      max-width: 1200px;
      margin: 0 auto;
      padding: 30px 20px 28px;
    }
    .pageBannerCopy {
      max-width: 780px;
    }
    .pageBannerKicker {
      display: inline-flex;
      align-items: center;
      padding: 6px 12px;
      border-radius: 999px;
      background: rgba(26, 115, 232, 0.10);
      color: var(--blue);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      margin-bottom: 14px;
    }
    .pageBannerTitle {
      margin: 0 0 10px;
      font-size: 38px;
      line-height: 1.08;
      font-weight: 500;
      letter-spacing: -0.03em;
    }
    .pageBannerMeta {
      color: var(--muted);
      max-width: 720px;
    }
    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px 20px 56px;
    }
    a {
      color: var(--blue);
      text-decoration: none;
    }
    .reportShell {
      max-width: 1080px;
      margin: 0 auto;
    }
    .topline {
      font-size: 12px;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--subtle);
      margin: 0 0 8px;
      font-weight: 700;
    }
    h1 {
      margin: 0 0 10px;
      font-size: 34px;
      line-height: 1.15;
      font-weight: 500;
      letter-spacing: -0.02em;
    }
    h2 {
      margin: 0 0 14px;
      font-size: 14px;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--muted);
      font-weight: 700;
    }
    p {
      line-height: 1.55;
      font-size: 14px;
      margin: 0;
    }
    .hero {
      display: grid;
      gap: 18px;
      margin-bottom: 20px;
    }
    .hero-card {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .hero-strip {
      height: 4px;
      width: 140px;
      background: var(--blue);
      border-radius: 0 0 8px 8px;
      margin-left: 28px;
    }
    .hero-body {
      padding: 22px 28px 24px;
    }
    .hero-summary {
      color: var(--muted);
      max-width: 860px;
      margin-top: 6px;
    }
    .hero-note {
      margin-top: 14px;
      padding: 14px 16px;
      border-radius: 12px;
      background: var(--blue-soft);
      color: #174ea6;
      font-size: 14px;
    }
    .tabs {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 0 0 18px;
    }
    .tab-button {
      border: 1px solid #d2d8e2;
      background: #ffffff;
      color: var(--muted);
      border-radius: 999px;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .18s ease;
    }
    .tab-button:hover {
      border-color: #b7cdf8;
      color: var(--blue);
    }
    .tab-button.is-active {
      background: var(--blue-soft);
      border-color: #c6dafc;
      color: var(--blue);
    }
    .tab-panel {
      display: none;
    }
    .tab-panel.is-active {
      display: block;
    }
    .histories {
      border: 1px solid rgba(120, 72, 50, 0.12);
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.5);
      overflow: hidden;
    }
    .historiesToolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
      padding: 14px 16px;
      border-bottom: 1px solid rgba(120, 72, 50, 0.1);
      background: rgba(243, 230, 218, 0.35);
    }
    .historiesToolbarLabel {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .historiesFilters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 12px;
    }
    .historiesFilterOption {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.8rem;
      color: var(--text);
      cursor: pointer;
    }
    .historiesFilterCheckbox {
      margin: 0;
    }
    .historiesFilteredEmpty {
      padding: 28px 18px;
      text-align: center;
      color: var(--muted);
    }
    .historiesTable {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.82rem;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }
    .historiesTable th,
    .historiesTable td {
      padding: 8px 16px;
      text-align: right;
      border-bottom: 1px solid rgba(120, 72, 50, 0.1);
      vertical-align: top;
    }
    .historiesTable .alignLeft {
      text-align: left;
    }
    .historiesTable thead th {
      font-size: 0.68rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.03em;
      color: var(--muted);
      background: rgba(243, 230, 218, 0.6);
    }
    .historiesTable tbody tr:hover {
      background: rgba(255, 255, 255, 0.6);
    }
    .historiesTable .action {
      font-weight: 700;
      color: #c25a36;
    }
    .historiesTable .symbol {
      font-weight: 600;
      color: var(--text);
    }
    .historiesTable .note {
      white-space: normal;
      min-width: 320px;
      max-width: 640px;
      color: var(--muted);
      font-size: 0.78rem;
      line-height: 1.4;
      overflow-wrap: anywhere;
    }
    .termBadge {
      display: inline-block;
      padding: 1px 8px;
      border-radius: 999px;
      font-size: 0.68rem;
      font-weight: 600;
      letter-spacing: 0.03em;
    }
    .termBadge.long {
      background: rgba(47, 143, 87, 0.14);
      color: var(--sim-pos);
    }
    .termBadge.short {
      background: rgba(192, 57, 43, 0.12);
      color: var(--sim-neg);
    }
    .section-stack {
      display: grid;
      gap: 18px;
    }
    .mini-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    .mini-stat {
      background: #f8fafd;
      border: 1px solid #edf0f4;
      border-radius: 12px;
      padding: 14px;
    }
    .mini-stat-label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--subtle);
      margin-bottom: 8px;
      font-weight: 700;
    }
    .mini-stat-value {
      font-size: 22px;
      color: var(--text);
      font-weight: 500;
      line-height: 1.1;
    }
    .lot-card {
      border: 1px solid #edf0f4;
      border-radius: 12px;
      overflow: hidden;
      background: #fbfcff;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }
    .card {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      padding: 20px 20px 18px;
      box-shadow: var(--shadow);
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      margin: 0;
    }
    .stats div {
      background: #f8fafd;
      padding: 14px;
      border-radius: 12px;
      border: 1px solid #edf0f4;
    }
    .stats dt {
      font-size: 11px;
      text-transform: uppercase;
      color: var(--subtle);
      margin-bottom: 8px;
      font-weight: 700;
      letter-spacing: .06em;
    }
    .stats dd {
      margin: 0;
      font-size: 24px;
      line-height: 1.1;
      font-weight: 400;
      color: var(--text);
    }
    .value-positive { color: #188038; }
    .value-negative { color: #d93025; }
    .value-neutral { color: var(--text); }
    .list {
      padding-left: 18px;
      line-height: 1.65;
      margin: 0;
      color: var(--muted);
      font-size: 14px;
    }
    .list li + li {
      margin-top: 10px;
    }
    .muted { color: var(--muted); }
    .full {
      grid-column: 1 / -1;
    }
    .reportDoc {
      max-width: 1080px;
      margin: 0 auto;
      padding: 34px 38px 42px;
      border: 1px solid rgba(120, 72, 50, 0.12);
      border-radius: 22px;
      background: linear-gradient(180deg, rgba(255, 252, 247, 0.98), rgba(251, 244, 236, 0.98));
      box-shadow: 0 20px 50px rgba(120, 72, 50, 0.08);
    }
    .reportDocHeader {
      padding-bottom: 20px;
      border-bottom: 2px solid rgba(120, 72, 50, 0.1);
    }
    .reportKicker {
      display: inline-block;
      margin-bottom: 10px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .reportDocHeader h1 {
      margin: 0;
      font-size: 2.15rem;
      line-height: 1.05;
      color: var(--text);
      font-weight: 500;
      letter-spacing: 0;
    }
    .reportLead {
      margin: 14px 0 0;
      max-width: 760px;
      font-size: 1rem;
      line-height: 1.7;
      color: var(--muted);
    }
    .reportSection {
      padding-top: 26px;
    }
    .reportSection h2 {
      margin: 0 0 12px;
      font-size: 1.15rem;
      color: var(--text);
      font-weight: 600;
      letter-spacing: 0;
      text-transform: none;
    }
    .reportSummaryList {
      margin: 0;
      border-top: 1px solid rgba(120, 72, 50, 0.08);
    }
    .reportSummaryRow {
      display: grid;
      grid-template-columns: minmax(160px, 220px) minmax(0, 1fr);
      gap: 12px 20px;
      padding: 14px 0;
      border-bottom: 1px solid rgba(120, 72, 50, 0.08);
    }
    .reportSummaryRow dt {
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .reportSummaryRow dd {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin: 0;
      text-align: left;
    }
    .reportSummaryRow strong {
      font-size: 1.45rem;
      line-height: 1.1;
      color: var(--text);
    }
    .reportSummaryRow span {
      color: var(--muted);
    }
    .reportBodyStrong {
      margin: 0 0 6px;
      font-weight: 600;
      color: var(--text);
    }
    .reportBody,
    .reportMuted {
      margin: 0;
      line-height: 1.7;
    }
    .reportMuted {
      color: var(--muted);
    }
    .reportInlineMeta {
      font-size: 0.82rem;
      font-weight: 500;
      color: var(--muted);
    }
    .reportFacts {
      display: grid;
      gap: 10px;
      margin: 0;
    }
    .reportFacts div {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      padding-bottom: 10px;
      border-bottom: 1px solid rgba(120, 72, 50, 0.08);
    }
    .reportFacts div:last-child {
      padding-bottom: 0;
      border-bottom: none;
    }
    .reportFacts dt {
      color: var(--muted);
    }
    .reportFacts dd {
      margin: 0;
      text-align: right;
      font-weight: 600;
      color: var(--text);
    }
    .reportBullets {
      display: grid;
      gap: 10px;
      margin: 0;
      padding-left: 20px;
      line-height: 1.6;
      color: var(--text);
      font-size: 14px;
    }
    .reportEmptyLine,
    .reportEmpty {
      margin: 0;
      color: var(--muted);
    }
    .reportTableScroll {
      margin-top: 14px;
      overflow-x: auto;
      border: 1px solid rgba(120, 72, 50, 0.12);
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.55);
    }
    .reportTable {
      width: 100%;
      min-width: 880px;
      border-collapse: collapse;
      font-size: 0.84rem;
      font-variant-numeric: tabular-nums;
    }
    .reportTable th,
    .reportTable td {
      padding: 10px 12px;
      border-bottom: 1px solid rgba(120, 72, 50, 0.1);
      text-align: right;
      white-space: nowrap;
    }
    .reportTable thead th {
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: var(--muted);
      background: rgba(243, 230, 218, 0.55);
    }
    .reportTable tbody tr:hover {
      background: rgba(255, 255, 255, 0.7);
    }
    .reportTable tbody tr:last-child td {
      border-bottom: none;
    }
    .reportTable .alignLeft {
      text-align: left;
    }
    .reportPositionName {
      font-weight: 700;
      color: #7a4c35;
    }
    pre {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-word;
      background: #f8fafd;
      color: #334155;
      padding: 16px;
      border-radius: 12px;
      overflow: auto;
      border: 1px solid #edf0f4;
      font-size: 12px;
      line-height: 1.6;
      font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    }
    .file-meta {
      margin-bottom: 12px;
      font-size: 12px;
      color: var(--subtle);
      letter-spacing: .04em;
      text-transform: uppercase;
      font-weight: 700;
    }
    .timeline-chart-card {
      background: var(--sim-surface);
      border-color: var(--sim-border);
      box-shadow: none;
      padding: 16px;
    }
    .summaryHeader {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 10px;
    }
    .summaryLabel {
      display: block;
      font-size: 0.84rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--sim-muted);
    }
    .summaryValue {
      display: block;
      font-size: 2.2rem;
      font-weight: 700;
      line-height: 1.05;
      font-variant-numeric: tabular-nums;
      color: #2b2320;
    }
    .summaryChange {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }
    .summaryChangeAmount {
      display: block;
      font-size: 1.05rem;
      font-weight: 700;
    }
    .summaryRange {
      display: block;
      margin-top: 1px;
      font-size: 0.92rem;
      font-weight: 500;
      color: var(--sim-muted);
    }
    .pos { color: var(--sim-pos); }
    .neg { color: var(--sim-neg); }
    .timeline-wrap {
      position: relative;
      width: 100%;
      touch-action: none;
      padding-bottom: 34px;
    }
    .timeline-chart {
      display: block;
      width: 100%;
      cursor: crosshair;
    }
    .timeline-y-labels,
    .timeline-x-labels {
      position: absolute;
      inset: 0;
      pointer-events: none;
    }
    .chartGrid {
      stroke: var(--sim-border);
      stroke-width: 1;
    }
    .chartYLabel,
    .chartXLabel {
      position: absolute;
      font-size: 13px;
      color: var(--sim-muted);
      font-variant-numeric: tabular-nums;
      pointer-events: none;
      white-space: nowrap;
    }
    .chartYLabel {
      left: 0;
      text-align: right;
      transform: translateY(-50%);
    }
    .chartXLabel {
      transform: translateX(0);
    }
    .chartXLabel.end {
      transform: translateX(-100%);
    }
    .chartLine {
      fill: none;
      stroke: var(--sim-muted);
      stroke-width: 2;
      stroke-linejoin: round;
      stroke-linecap: round;
      vector-effect: non-scaling-stroke;
    }
    .chartLine.pos {
      stroke: var(--sim-pos);
    }
    .chartLine.neg {
      stroke: var(--sim-neg);
    }
    .chartArea {
      stroke: none;
      fill: rgba(122, 106, 93, 0.12);
    }
    .chartArea.pos {
      fill: rgba(47, 143, 87, 0.12);
    }
    .chartArea.neg {
      fill: rgba(192, 57, 43, 0.12);
    }
    .chartMarkerLine {
      stroke: var(--sim-border-strong);
      stroke-width: 1;
      stroke-dasharray: 3 3;
      vector-effect: non-scaling-stroke;
    }
    .chartMarkerDot {
      fill: var(--sim-muted);
      stroke: var(--sim-surface-strong);
      stroke-width: 2;
    }
    .chartMarkerDot.pos {
      fill: var(--sim-pos);
    }
    .chartMarkerDot.neg {
      fill: var(--sim-neg);
    }
    .chartTooltip {
      position: absolute;
      transform: translate(-50%, calc(-100% - 12px));
      display: flex;
      flex-direction: column;
      gap: 1px;
      padding: 6px 9px;
      background: var(--sim-surface-strong);
      border: 1px solid var(--sim-border-strong);
      border-radius: 8px;
      box-shadow: 0 6px 18px rgba(43, 35, 32, 0.12);
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
      pointer-events: none;
      z-index: 2;
      opacity: 0;
    }
    .chartTooltip.is-visible {
      opacity: 1;
    }
    .chartTooltipDate {
      font-size: 0.78rem;
      color: var(--sim-muted);
    }
    .chartTooltipValue {
      font-size: 1rem;
      font-weight: 700;
      color: #2b2320;
    }
    .chartTooltipChange {
      font-size: 0.82rem;
      font-weight: 600;
    }
    .timeline-empty {
      padding: 48px 18px;
      text-align: center;
      color: var(--sim-muted);
      border: 1px dashed var(--sim-border-strong);
      border-radius: 14px;
      background: var(--sim-surface);
    }
    .site-footer {
      margin-top: 24px;
      text-align: center;
      color: var(--muted);
      font-size: 13px;
    }
    .site-footer a {
      color: var(--blue);
      text-decoration: none;
    }
    .site-footer a:hover {
      text-decoration: underline;
    }
    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .mini-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .reportDoc { padding: 26px 22px 30px; }
    }
    @media (max-width: 640px) {
      .pageBannerInner { padding: 24px 14px 22px; }
      .pageBannerTitle { font-size: 30px; }
      main { padding: 20px 14px 40px; }
      .hero-body { padding: 20px; }
      .hero-strip { margin-left: 20px; }
      h1 { font-size: 28px; }
      .stats dd { font-size: 20px; }
      .mini-grid { grid-template-columns: 1fr; }
      .summaryHeader { flex-direction: column; }
      .summaryValue { font-size: 1.85rem; }
      .summaryChange { text-align: left; }
      .chartYLabel,
      .chartXLabel { font-size: 11px; }
      .reportDocHeader h1 { font-size: 1.75rem; }
      .reportSummaryRow { grid-template-columns: minmax(0, 1fr); }
      .reportFacts div { flex-direction: column; }
      .reportFacts dd { text-align: left; }
    }
  </style>
</head>
<body>
  <div class="pageBanner">
    <div class="pageBannerInner">
      <div class="pageBannerCopy">
        <p class="topline"><a href="/index.php">All Reports</a> / Report #<?= h($row['id']) ?></p>
        <span class="pageBannerKicker">Simulation Report</span>
        <h1 class="pageBannerTitle"><?= h($reportHeaderTitle) ?></h1>
        <p class="pageBannerMeta">
          <?= h($reportHeaderSummary) ?><br>
          Created at <?= h($row['created_at']) ?> / v<?= h($row['version']) ?>
          <?php if (!empty($row['updated_by_name'])): ?>
            / Updated by <?= h($row['updated_by_name']) ?>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>
  <main>
    <div class="reportShell">
      <?php if (!$isValidJson): ?>
        <div class="hero-card">
          <div class="hero-strip"></div>
          <div class="hero-body">
            <h1>Report #<?= h($row['id']) ?></h1>
            <p class="muted">
              Created at <?= h($row['created_at']) ?> / v<?= h($row['version']) ?>
              <?php if (!empty($row['updated_by_name'])): ?>
                / Updated by <?= h($row['updated_by_name']) ?>
              <?php endif; ?>
            </p>
          </div>
        </div>
        <div class="card" style="margin-top: 18px;">
          <pre><?= h($row['report_json']) ?></pre>
        </div>
      <?php else: ?>
        <?php
        $objective = is_array($report['objective'] ?? null) ? $report['objective'] : [];
        $strategy = is_array($report['strategy'] ?? null) ? $report['strategy'] : [];
        $thesis = is_array($report['thesis'] ?? null) ? $report['thesis'] : [];
        $simulation = is_array($report['simulation'] ?? null) ? $report['simulation'] : [];
        $activity = is_array($report['activity'] ?? null) ? $report['activity'] : [];
        $portfolioSummary = is_array($report['portfolioSummary'] ?? null) ? $report['portfolioSummary'] : [];
        $benchmark = is_array($report['benchmark'] ?? null) ? $report['benchmark'] : [];
        $portfolio = is_array($report['portfolio'] ?? null) ? $report['portfolio'] : [];
        $positions = is_array($report['positions'] ?? null) ? $report['positions'] : [];
        $taxes = is_array($report['taxes'] ?? null) ? $report['taxes'] : [];
        $takeaways = is_array($report['takeaways'] ?? null) ? $report['takeaways'] : [];
        $agentLearning = is_array($report['agentLearning'] ?? null) ? $report['agentLearning'] : [];
        $context = is_array($report['context'] ?? null) ? $report['context'] : [];
        $timelineChangePercent = null;
        if (($valuesSummary['first']['value'] ?? null) !== null) {
            $firstValue = (float) $valuesSummary['first']['value'];
            if ($firstValue != 0.0 && ($valuesSummary['last']['value'] ?? null) !== null) {
                $timelineChangePercent = (((float) $valuesSummary['last']['value'] - $firstValue) / $firstValue) * 100;
            }
        }
        if ($timelineChangePercent === null && ($valuesSummary['change'] ?? null) !== null) {
            $timelineChangePercent = 0.0;
        }
        $timelineToneClass = tone_class(isset($valuesSummary['change']) ? (float) $valuesSummary['change'] : null);
        $factualFindings = array_merge(
            is_array($takeaways['worked'] ?? null) ? $takeaways['worked'] : [],
            is_array($takeaways['didNotWork'] ?? null) ? $takeaways['didNotWork'] : []
        );
        $periodStart = section_value($simulation, 'simStartDate', '');
        $periodLabel = $periodStart === ''
            ? section_value($simulation, 'simEndDate')
            : $periodStart . ' -> ' . section_value($simulation, 'simEndDate');
        ?>
        <div class="tabs" role="tablist" aria-label="Report data tabs">
          <button class="tab-button is-active" type="button" role="tab" aria-selected="true" aria-controls="tab-summary" data-tab-target="tab-summary">Report Summary</button>
          <button class="tab-button" type="button" role="tab" aria-selected="false" aria-controls="tab-history" data-tab-target="tab-history">Activity Log</button>
          <button class="tab-button" type="button" role="tab" aria-selected="false" aria-controls="tab-values" data-tab-target="tab-values">Value Timeline</button>
        </div>

        <?php require __DIR__ . '/parts/reports/tab-summary.php'; ?>
        <?php require __DIR__ . '/parts/reports/tab-history.php'; ?>
        <?php require __DIR__ . '/parts/reports/tab-values.php'; ?>
      <?php endif; ?>
    </div>
    <?php require __DIR__ . '/footer.php'; ?>
  </main>
  <script>
    (function () {
      const buttons = Array.from(document.querySelectorAll('.tab-button'));
      const panels = Array.from(document.querySelectorAll('.tab-panel'));
      const historyFilters = Array.from(document.querySelectorAll('[data-history-filter]'));
      const historyRows = Array.from(document.querySelectorAll('[data-history-type]'));
      const timelineCharts = Array.from(document.querySelectorAll('[data-timeline-chart]'));

      buttons.forEach((button) => {
        button.addEventListener('click', () => {
          const targetId = button.getAttribute('data-tab-target');

          buttons.forEach((item) => {
            item.classList.remove('is-active');
            item.setAttribute('aria-selected', 'false');
          });

          panels.forEach((panel) => {
            panel.classList.remove('is-active');
          });

          button.classList.add('is-active');
          button.setAttribute('aria-selected', 'true');

          const targetPanel = document.getElementById(targetId);
          if (targetPanel) {
            targetPanel.classList.add('is-active');
          }
        });
      });

      function applyHistoryFilters() {
        const selected = new Set(
          historyFilters
            .filter((input) => input instanceof HTMLInputElement && input.checked)
            .map((input) => input.getAttribute('data-history-filter'))
            .filter((value) => value !== null)
        );
        let visibleCount = 0;

        historyRows.forEach((row) => {
          const type = row.getAttribute('data-history-type');
          const shouldShow = type !== null && selected.has(type);
          row.style.display = shouldShow ? '' : 'none';
          if (shouldShow) {
            visibleCount += 1;
          }
        });

        const table = document.querySelector('.historiesTable');
        const tableScroll = table?.parentElement;
        if (!table || !tableScroll) {
          return;
        }

        let emptyState = tableScroll.querySelector('.historiesFilteredEmpty');
        if (visibleCount === 0) {
          table.style.display = 'none';
          if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.className = 'historiesFilteredEmpty';
            emptyState.textContent = 'No history rows match the selected data types.';
            tableScroll.appendChild(emptyState);
          }
        } else {
          table.style.display = '';
          if (emptyState) {
            emptyState.remove();
          }
        }
      }

      historyFilters.forEach((input) => {
        if (!(input instanceof HTMLInputElement)) {
          return;
        }

        input.addEventListener('change', applyHistoryFilters);
      });

      const PAD = { top: 16, right: 14, bottom: 36, left: 84 };
      const GRID_LINE_COUNT = 4;
      const HEIGHT_DIVISOR = 2.6;
      const MIN_HEIGHT = 240;
      const MAX_HEIGHT = 400;
      const FALLBACK_WIDTH = 760;

      function formatMoneyValue(value) {
        return new Intl.NumberFormat('en-US', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }).format(value);
      }

      function formatSignedMoneyValue(value) {
        return `${value >= 0 ? '+' : '-'}${formatMoneyValue(Math.abs(value))}`;
      }

      function formatSignedPercentValue(value) {
        return `${value >= 0 ? '+' : '-'}${formatMoneyValue(Math.abs(value))}%`;
      }

      function tone(value) {
        return value >= 0 ? 'pos' : 'neg';
      }

      function buildChartGeometry(snapshots, width, height) {
        const plotWidth = width - PAD.left - PAD.right;
        const plotHeight = height - PAD.top - PAD.bottom;
        const plotBottom = PAD.top + plotHeight;
        const values = snapshots.map((snapshot) => Number(snapshot.value));
        const rawMin = Math.min(...values);
        const rawMax = Math.max(...values);
        const min = rawMin === rawMax ? rawMin - 1 : rawMin;
        const max = rawMin === rawMax ? rawMax + 1 : rawMax;

        const points = snapshots.map((snapshot, index) => {
          const ratioX = snapshots.length === 1 ? 0.5 : index / (snapshots.length - 1);
          const ratioY = (Number(snapshot.value) - min) / (max - min);

          return {
            x: PAD.left + ratioX * plotWidth,
            y: PAD.top + (1 - ratioY) * plotHeight,
            snapshot
          };
        });

        const gridLines = Array.from({ length: GRID_LINE_COUNT + 1 }, (_, index) => {
          const ratio = index / GRID_LINE_COUNT;

          return {
            y: PAD.top + ratio * plotHeight,
            value: max - ratio * (max - min)
          };
        });

        return { points, gridLines, plotBottom };
      }

      function renderTimelineChart(container) {
        const rawPoints = container.getAttribute('data-points');
        if (!rawPoints) {
          return;
        }

        let snapshots = [];
        try {
          snapshots = JSON.parse(rawPoints);
        } catch (error) {
          return;
        }

        if (!Array.isArray(snapshots) || snapshots.length === 0) {
          return;
        }

        const wrap = container.closest('.timeline-wrap');
        if (!wrap) {
          return;
        }

        const yLabelsHost = wrap.querySelector('[data-timeline-y-labels]');
        const xLabelsHost = wrap.querySelector('[data-timeline-x-labels]');
        const tooltip = wrap.querySelector('[data-timeline-tooltip]');
        const width = wrap.clientWidth || FALLBACK_WIDTH;
        const height = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.round(width / HEIGHT_DIVISOR)));
        const { points, gridLines, plotBottom } = buildChartGeometry(snapshots, width, height);
        const first = snapshots[0];
        const linePath = points
          .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`)
          .join(' ');
        const areaPath = `${linePath} L ${points[points.length - 1].x.toFixed(1)} ${plotBottom.toFixed(1)} L ${points[0].x.toFixed(1)} ${plotBottom.toFixed(1)} Z`;
        const changeTotal = Number(snapshots[snapshots.length - 1].value) - Number(first.value);
        const trendTone = container.getAttribute('data-trend') || tone(changeTotal);

        container.innerHTML = `
          <svg class="timeline-svg" width="${width}" height="${height}" role="img" aria-hidden="true">
            ${gridLines.map((line) => `<line class="chartGrid" x1="${PAD.left}" y1="${line.y}" x2="${width - PAD.right}" y2="${line.y}" />`).join('')}
            <path class="chartArea ${trendTone}" d="${areaPath}" />
            <path class="chartLine ${trendTone}" d="${linePath}" />
            <line class="chartMarkerLine" x1="0" y1="0" x2="0" y2="0" style="display:none" />
            <circle class="chartMarkerDot ${trendTone}" cx="0" cy="0" r="4" style="display:none" />
          </svg>
        `;

        if (yLabelsHost) {
          yLabelsHost.innerHTML = '';
          gridLines.forEach((line) => {
            const label = document.createElement('span');
            label.className = 'chartYLabel';
            label.style.top = `${line.y}px`;
            label.style.width = `${PAD.left - 8}px`;
            label.textContent = formatMoneyValue(line.value);
            yLabelsHost.appendChild(label);
          });
        }

        if (xLabelsHost) {
          xLabelsHost.innerHTML = '';

          const startLabel = document.createElement('span');
          startLabel.className = 'chartXLabel';
          startLabel.style.left = `${points[0].x}px`;
          startLabel.style.top = `${plotBottom + 14}px`;
          startLabel.textContent = String(first.date ?? '');
          xLabelsHost.appendChild(startLabel);

          const endLabel = document.createElement('span');
          endLabel.className = 'chartXLabel end';
          endLabel.style.left = `${points[points.length - 1].x}px`;
          endLabel.style.top = `${plotBottom + 14}px`;
          endLabel.textContent = String(snapshots[snapshots.length - 1].date ?? '');
          xLabelsHost.appendChild(endLabel);
        }

        const markerLine = container.querySelector('.chartMarkerLine');
        const markerDot = container.querySelector('.chartMarkerDot');

        function clearMarker() {
          if (tooltip) {
            tooltip.classList.remove('is-visible');
          }
          if (markerLine) {
            markerLine.style.display = 'none';
          }
          if (markerDot) {
            markerDot.style.display = 'none';
          }
        }

        function updateMarker(index) {
          if (!tooltip || !markerLine || !markerDot) {
            return;
          }

          const active = points[index];
          const activeChange = Number(active.snapshot.value) - Number(first.value);
          const activeChangePercent = Number(first.value) === 0 ? 0 : (activeChange / Number(first.value)) * 100;
          const tooltipDate = tooltip.querySelector('.chartTooltipDate');
          const tooltipValue = tooltip.querySelector('.chartTooltipValue');
          const tooltipChange = tooltip.querySelector('.chartTooltipChange');

          markerLine.setAttribute('x1', String(active.x));
          markerLine.setAttribute('y1', String(PAD.top));
          markerLine.setAttribute('x2', String(active.x));
          markerLine.setAttribute('y2', String(plotBottom));
          markerLine.style.display = '';
          markerDot.setAttribute('cx', String(active.x));
          markerDot.setAttribute('cy', String(active.y));
          markerDot.style.display = '';

          if (tooltipDate) {
            tooltipDate.textContent = String(active.snapshot.date ?? '');
          }
          if (tooltipValue) {
            tooltipValue.textContent = formatMoneyValue(Number(active.snapshot.value));
          }
          if (tooltipChange) {
            tooltipChange.textContent = `${formatSignedMoneyValue(activeChange)} (${formatSignedPercentValue(activeChangePercent)})`;
            tooltipChange.className = `chartTooltipChange ${tone(activeChange)}`;
          }

          tooltip.style.left = `${active.x}px`;
          tooltip.style.top = `${active.y}px`;
          tooltip.classList.add('is-visible');
        }

        clearMarker();

        wrap.onpointermove = (event) => {
          const offsetX = event.clientX - wrap.getBoundingClientRect().left;
          const ratio = points.length === 1 ? 0 : (offsetX - PAD.left) / (width - PAD.left - PAD.right);
          const nearest = Math.round(ratio * (points.length - 1));
          updateMarker(Math.max(0, Math.min(points.length - 1, nearest)));
        };
        wrap.onpointerleave = clearMarker;
      }

      const resizeObservers = [];
      timelineCharts.forEach((container) => {
        const wrap = container.closest('.timeline-wrap');
        renderTimelineChart(container);

        if (wrap && 'ResizeObserver' in window) {
          const observer = new ResizeObserver(() => renderTimelineChart(container));
          observer.observe(wrap);
          resizeObservers.push(observer);
        }
      });
    }());
  </script>
</body>
</html>
