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
    'SELECT id, report_json, created_at, account_json_path, history_log_path, meta_json_path, values_log_path FROM stock_reports WHERE id = :id LIMIT 1'
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
$valueSnapshots = parse_value_snapshots($valuesFileContent);
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

        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) < 2) {
            continue;
        }

        $entry = [
            'timestamp' => array_shift($parts),
            'type' => array_shift($parts),
            'stock' => '',
            'quantity' => '',
            'price' => '',
            'cash' => '',
            'sim' => '',
            'term' => '',
            'note' => '',
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
            } elseif ($key === 'cash') {
                $entry['cash'] = $value;
            } elseif ($key === 'sim') {
                $entry['sim'] = $value;
            } elseif ($key === 'term') {
                $entry['term'] = $value;
            } elseif ($key === 'note') {
                $entry['note'] = $value;
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
        'recent' => array_slice(array_reverse($entries), 0, 120),
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Report #<?= h($row['id']) ?></title>
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
    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 28px 20px 56px;
    }
    a {
      color: var(--blue);
      text-decoration: none;
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
    .data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }
    .data-table th,
    .data-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #edf0f4;
      text-align: left;
      vertical-align: top;
    }
    .data-table th {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--subtle);
      font-weight: 700;
    }
    .data-table td {
      color: var(--muted);
    }
    .data-table td strong {
      color: var(--text);
      font-weight: 600;
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 10px 14px;
      padding: 14px 16px;
      margin: 0 0 14px;
      border: 1px solid #edf0f4;
      border-radius: 12px;
      background: #f8fafd;
    }
    .toolbar-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--subtle);
    }
    .filters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 12px;
    }
    .filter-option {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: var(--text);
      cursor: pointer;
    }
    .filter-checkbox {
      margin: 0;
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
    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
      .mini-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .reportDoc { padding: 26px 22px 30px; }
    }
    @media (max-width: 640px) {
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
  <main>
    <p class="topline"><a href="/index.php">All Reports</a> / Report #<?= h($row['id']) ?></p>
    <?php if (!$isValidJson): ?>
      <div class="hero-card">
        <div class="hero-strip"></div>
        <div class="hero-body">
          <h1>Report #<?= h($row['id']) ?></h1>
          <p class="muted">Created at <?= h($row['created_at']) ?></p>
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

      <section id="tab-summary" class="tab-panel is-active" role="tabpanel">
        <article class="reportDoc">
          <header class="reportDocHeader">
            <span class="reportKicker">Simulation Report</span>
            <h1><?= h(section_value($strategy, 'name', 'Unnamed strategy')) ?></h1>
            <p class="reportLead"><?= h(section_value($takeaways, 'summary', 'No written assessment is available for this report yet.')) ?></p>
          </header>

          <section class="reportSection">
            <h2>Thesis</h2>
            <p class="reportBody"><?= h(section_value($thesis, 'summary', 'No forward-looking thesis was provided.')) ?></p>
          </section>

          <section class="reportSection">
            <h2>Goal</h2>
            <p class="reportBodyStrong"><?= h(section_value($objective, 'title', 'Unspecified objective')) ?></p>
            <p class="reportMuted">Primary metric: <?= h(section_value($objective, 'primaryMetric', '—')) ?></p>
          </section>

          <section class="reportSection">
            <h2>Period</h2>
            <dl class="reportFacts">
              <div>
                <dt>Simulation range</dt>
                <dd><?= h($periodLabel) ?></dd>
              </div>
              <div>
                <dt>Starting value</dt>
                <dd><?= h(isset($simulation['startingValue']) ? format_money_value($simulation['startingValue']) : '—') ?></dd>
              </div>
              <div>
                <dt>Principal contributed</dt>
                <dd><?= h(format_money_value($portfolioSummary['principal'] ?? 0)) ?></dd>
              </div>
              <div>
                <dt>Ending cash</dt>
                <dd><?= h(format_money_value($simulation['endingCash'] ?? 0)) ?></dd>
              </div>
            </dl>
          </section>

          <section class="reportSection">
            <h2>Strategy Rules</h2>
            <p class="reportBodyStrong"><?= h(section_value($strategy, 'name', 'Unnamed strategy')) ?> <span class="reportInlineMeta"><?= h(section_value($strategy, 'version')) ?></span></p>
            <?php $constraints = is_array($objective['constraints'] ?? null) ? $objective['constraints'] : []; ?>
            <?php if ($constraints === []): ?>
              <p class="reportEmptyLine">—</p>
            <?php else: ?>
              <ul class="reportBullets">
                <?php foreach ($constraints as $item): ?>
                  <li><?= h((string) $item) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <section class="reportSection">
            <h2>Result</h2>
            <dl class="reportSummaryList">
              <div class="reportSummaryRow">
                <dt>Ending value</dt>
                <dd>
                  <strong><?= h(format_money_value($portfolioSummary['currentTotal'] ?? 0)) ?></strong>
                  <span>Portfolio value at the final available date</span>
                </dd>
              </div>
              <div class="reportSummaryRow">
                <dt>Total gain/loss</dt>
                <dd>
                  <strong class="<?= h(metric_value_class('gain', $portfolioSummary['totalGainLoss'] ?? 0)) ?>"><?= h(format_signed_money_value(isset($portfolioSummary['totalGainLoss']) ? (float) $portfolioSummary['totalGainLoss'] : null)) ?></strong>
                  <span><?= h(isset($portfolioSummary['totalReturnPct']) ? format_signed_percent_value((float) $portfolioSummary['totalReturnPct']) : '—') ?></span>
                </dd>
              </div>
              <div class="reportSummaryRow">
                <dt>Avg yearly gain</dt>
                <dd>
                  <strong class="<?= h(metric_value_class('return', $portfolioSummary['annualizedReturnPct'] ?? 0)) ?>"><?= h(isset($portfolioSummary['annualizedReturnPct']) ? format_signed_percent_value((float) $portfolioSummary['annualizedReturnPct']) : '—') ?></strong>
                  <span>Money-weighted annualized return using the recorded deposit schedule</span>
                </dd>
              </div>
              <div class="reportSummaryRow">
                <dt><?= h(section_value($benchmark, 'stockCode', 'SPY')) ?> yearly gain</dt>
                <dd>
                  <strong class="<?= h(metric_value_class('return', $benchmark['annualizedReturnPct'] ?? 0)) ?>"><?= h(isset($benchmark['annualizedReturnPct']) ? format_signed_percent_value((float) $benchmark['annualizedReturnPct']) : '—') ?></strong>
                  <span>
                    <?php if (isset($benchmark['endingValue'])): ?>
                      <?= h(section_value($benchmark, 'stockCode', 'SPY')) ?> ending value: <?= h(format_money_value($benchmark['endingValue'])) ?>
                    <?php else: ?>
                      <?= h(section_value($benchmark, 'methodology', 'Benchmark data unavailable.')) ?>
                    <?php endif; ?>
                  </span>
                </dd>
              </div>
              <div class="reportSummaryRow">
                <dt>Max drawdown</dt>
                <dd>
                  <strong class="<?= h(metric_value_class('drawdown', $portfolio['maxDrawdownPct'] ?? 0)) ?>"><?= h(isset($portfolio['maxDrawdownPct']) ? format_signed_percent_value((float) $portfolio['maxDrawdownPct']) : '—') ?></strong>
                  <span><?= h(format_display_value($portfolio['openPositionCount'] ?? 0)) ?> open positions at the end of the run</span>
                </dd>
              </div>
            </dl>
          </section>

          <section class="reportSection">
            <h2>Run Facts</h2>
            <dl class="reportFacts">
              <div>
                <dt>Largest position</dt>
                <dd><?= h(number_format((float) ($portfolio['largestPositionPct'] ?? 0), 2, '.', ',')) ?>%</dd>
              </div>
              <div>
                <dt>Cash position</dt>
                <dd><?= h(format_money_value($simulation['endingCash'] ?? 0)) ?> (<?= h(number_format((float) ($portfolio['cashPct'] ?? 0), 2, '.', ',')) ?>%)</dd>
              </div>
              <div>
                <dt>Unique stocks traded</dt>
                <dd><?= h(section_value($activity, 'uniqueStocksTraded', '0')) ?></dd>
              </div>
            </dl>
          </section>

          <section class="reportSection">
            <h2>Tax Profile</h2>
            <dl class="reportFacts">
              <div>
                <dt>Unrealized gain exposure</dt>
                <dd><?= h(format_signed_money_value(isset($portfolioSummary['unrealizedGainLoss']) ? (float) $portfolioSummary['unrealizedGainLoss'] : null)) ?></dd>
              </div>
              <div>
                <dt>Long-term tax</dt>
                <dd><?= h(format_money_value($taxes['longTermTax'] ?? 0)) ?></dd>
              </div>
              <div>
                <dt>Short-term tax</dt>
                <dd><?= h(format_money_value($taxes['shortTermTax'] ?? 0)) ?></dd>
              </div>
              <div>
                <dt>Dividend tax</dt>
                <dd><?= h(format_money_value($taxes['dividendTax'] ?? 0)) ?></dd>
              </div>
              <div>
                <dt>Total estimated tax</dt>
                <dd><?= h(format_money_value($taxes['estimatedTax'] ?? 0)) ?></dd>
              </div>
            </dl>
          </section>

          <section class="reportSection">
            <h2>Strategy Check</h2>
            <?php if ($factualFindings === []): ?>
              <p class="reportEmptyLine">—</p>
            <?php else: ?>
              <ul class="reportBullets">
                <?php foreach ($factualFindings as $item): ?>
                  <li><?= h(scored_item_text($item)) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>

          <?php if (trim(section_value($report, 'note', '')) !== ''): ?>
            <section class="reportSection">
              <h2>Note</h2>
              <p class="reportBody"><?= h(section_value($report, 'note')) ?></p>
            </section>
          <?php endif; ?>
        </article>
      </section>

      <section id="tab-history" class="tab-panel" role="tabpanel">
        <div class="section-stack">
          <section class="card">
            <div class="file-meta"><?= h($row['history_log_path']) ?></div>
            <div class="mini-grid">
              <div class="mini-stat">
                <div class="mini-stat-label">Total events</div>
                <div class="mini-stat-value"><?= h(format_display_value($historySummary['total'])) ?></div>
              </div>
              <?php foreach ($historySummary['counts'] as $type => $count): ?>
                <div class="mini-stat">
                  <div class="mini-stat-label"><?= h($type) ?></div>
                  <div class="mini-stat-value"><?= h(format_display_value($count)) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <div class="toolbar">
            <span class="toolbar-label">Show types</span>
            <div class="filters" role="group" aria-label="Filter activity log by event type">
              <?php foreach ($historyTypes as $type): ?>
                <label class="filter-option">
                  <input class="filter-checkbox" type="checkbox" checked data-history-filter="<?= h($type) ?>">
                  <span><?= h($type) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <section class="card">
            <h2>Recent Activity</h2>
            <table class="data-table">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>Sim date</th>
                  <th>Stock</th>
                  <th>Qty</th>
                  <th>Price</th>
                  <th>Cash</th>
                  <th>Note</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historySummary['recent'] as $entry): ?>
                  <tr data-history-type="<?= h($entry['type']) ?>">
                    <td><strong><?= h($entry['type']) ?></strong></td>
                    <td><?= h($entry['sim']) ?></td>
                    <td><?= h($entry['stock']) ?></td>
                    <td><?= h(format_display_value($entry['quantity'])) ?></td>
                    <td><?= h(format_display_value($entry['price'])) ?></td>
                    <td class="<?= h(metric_value_class('cash', $entry['cash'])) ?>"><?= h(format_display_value($entry['cash'])) ?></td>
                    <td><?= h($entry['note']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </section>
        </div>
      </section>

      <section id="tab-values" class="tab-panel" role="tabpanel">
        <div class="section-stack">
          <section class="card">
            <div class="file-meta"><?= h($row['values_log_path']) ?></div>
            <div class="mini-grid">
              <div class="mini-stat">
                <div class="mini-stat-label">Snapshots</div>
                <div class="mini-stat-value"><?= h(format_display_value($valuesSummary['count'])) ?></div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">First value</div>
                <div class="mini-stat-value"><?= h(format_display_value($valuesSummary['first']['value'] ?? '-')) ?></div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Latest value</div>
                <div class="mini-stat-value"><?= h(format_display_value($valuesSummary['last']['value'] ?? '-')) ?></div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Net change</div>
                <div class="mini-stat-value <?= h(metric_value_class('gain', $valuesSummary['change'])) ?>"><?= h(format_display_value($valuesSummary['change'] ?? '-')) ?></div>
              </div>
            </div>
          </section>

          <section class="card timeline-chart-card">
            <header class="summaryHeader">
              <div>
                <span class="summaryLabel">Total Value</span>
                <span class="summaryValue"><?= h(format_money_value($valuesSummary['last']['value'] ?? '-')) ?></span>
              </div>
              <div class="summaryChange <?= h($timelineToneClass) ?>">
                <span class="summaryChangeAmount">
                  <?= h(format_signed_money_value(isset($valuesSummary['change']) ? (float) $valuesSummary['change'] : null)) ?>
                  <?php if ($timelineChangePercent !== null): ?>
                    (<?= h(format_signed_percent_value((float) $timelineChangePercent)) ?>)
                  <?php endif; ?>
                </span>
                <span class="summaryRange"><?= h($valuesSummary['first']['date'] ?? '-') ?> -> <?= h($valuesSummary['last']['date'] ?? '-') ?></span>
              </div>
            </header>

            <?php if ($valueSnapshots === []): ?>
              <div class="timeline-empty">No timeline snapshots are available yet.</div>
            <?php else: ?>
              <div class="timeline-wrap">
                <div
                  class="timeline-chart"
                  data-timeline-chart
                  data-trend="<?= h($timelineToneClass) ?>"
                  aria-label="Portfolio value history from <?= h(format_timeline_date($valuesSummary['first']['date'] ?? null)) ?> to <?= h(format_timeline_date($valuesSummary['last']['date'] ?? null)) ?>"
                  data-points="<?= h(json_encode($valueSnapshots, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
                ></div>
                <div class="timeline-y-labels" data-timeline-y-labels></div>
                <div class="timeline-x-labels" data-timeline-x-labels></div>
                <div class="chartTooltip" data-timeline-tooltip>
                  <div class="chartTooltipDate"></div>
                  <div class="chartTooltipValue"></div>
                  <div class="chartTooltipChange"></div>
                </div>
              </div>
            <?php endif; ?>
          </section>

        </div>
      </section>
    <?php endif; ?>
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

        historyRows.forEach((row) => {
          const type = row.getAttribute('data-history-type');
          row.style.display = type !== null && selected.has(type) ? '' : 'none';
        });
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
