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
    'SELECT id, report_json, created_at FROM stock_reports WHERE id = :id LIMIT 1'
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

function format_display_value(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return number_format((float) $value, is_float($value) ? 2 : 0, '.', ',');
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
        || str_contains($labelLower, 'tax');

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
    @media (max-width: 900px) {
      .grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      main { padding: 20px 14px 40px; }
      .hero-body { padding: 20px; }
      .hero-strip { margin-left: 20px; }
      h1 { font-size: 28px; }
      .stats dd { font-size: 20px; }
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
      $simulation = is_array($report['simulation'] ?? null) ? $report['simulation'] : [];
      $activity = is_array($report['activity'] ?? null) ? $report['activity'] : [];
      $portfolioSummary = is_array($report['portfolioSummary'] ?? null) ? $report['portfolioSummary'] : [];
      $benchmark = is_array($report['benchmark'] ?? null) ? $report['benchmark'] : [];
      $portfolio = is_array($report['portfolio'] ?? null) ? $report['portfolio'] : [];
      $taxes = is_array($report['taxes'] ?? null) ? $report['taxes'] : [];
      $takeaways = is_array($report['takeaways'] ?? null) ? $report['takeaways'] : [];
      $agentLearning = is_array($report['agentLearning'] ?? null) ? $report['agentLearning'] : [];
      $context = is_array($report['context'] ?? null) ? $report['context'] : [];
      ?>
      <div class="hero">
        <div class="hero-card">
          <div class="hero-strip"></div>
          <div class="hero-body">
            <p class="topline"><?= h(section_value($report, 'sessionId')) ?> / <?= h($row['created_at']) ?></p>
            <h1><?= h(section_value($objective, 'title', 'Simulation Report')) ?></h1>
            <p class="hero-summary"><strong><?= h(section_value($strategy, 'name')) ?></strong> (<?= h(section_value($strategy, 'version')) ?>)<?php if (section_value($takeaways, 'summary', '') !== ''): ?> · <?= h(section_value($takeaways, 'summary', section_value($strategy, 'summary'))) ?><?php endif; ?></p>
            <div class="hero-note"><?= h(section_value($report, 'note')) ?></div>
          </div>
        </div>
      </div>

      <div class="grid">
        <section class="card">
          <h2>Simulation</h2>
          <?php render_key_values([
              'Start Date' => section_value($simulation, 'simStartDate'),
              'End Date' => section_value($simulation, 'simEndDate'),
              'Starting Value' => section_value($simulation, 'startingValue'),
              'Ending Value' => section_value($simulation, 'endingValue'),
              'Total Return %' => section_value($simulation, 'totalReturnPct'),
              'Annualized Return %' => section_value($simulation, 'annualizedReturnPct'),
          ]); ?>
        </section>

        <section class="card">
          <h2>Portfolio Summary</h2>
          <?php render_key_values([
              'Principal' => section_value($portfolioSummary, 'principal'),
              'Current Total' => section_value($portfolioSummary, 'currentTotal'),
              'Gain / Loss' => section_value($portfolioSummary, 'totalGainLoss'),
              'Return %' => section_value($portfolioSummary, 'totalReturnPct'),
              'Unrealized Gain / Loss' => section_value($portfolioSummary, 'unrealizedGainLoss'),
              'Unrealized Gain / Loss %' => section_value($portfolioSummary, 'unrealizedGainLossPct'),
          ]); ?>
        </section>

        <section class="card">
          <h2>Activity</h2>
          <?php render_key_values([
              'History Events' => section_value($activity, 'historyEventCount'),
              'Buys' => section_value($activity, 'buyCount'),
              'Sells' => section_value($activity, 'sellCount'),
              'Dividends' => section_value($activity, 'dividendCount'),
              'Interest' => section_value($activity, 'interestCount'),
              'Unique Stocks' => section_value($activity, 'uniqueStocksTraded'),
          ]); ?>
        </section>

        <section class="card">
          <h2>Risk And Benchmark</h2>
          <?php render_key_values([
              'Benchmark' => section_value($benchmark, 'stockCode'),
              'Benchmark Ending Value' => section_value($benchmark, 'endingValue'),
              'Benchmark Annualized %' => section_value($benchmark, 'annualizedReturnPct'),
              'Open Positions' => section_value($portfolio, 'openPositionCount'),
              'Largest Position %' => section_value($portfolio, 'largestPositionPct'),
              'Max Drawdown %' => section_value($portfolio, 'maxDrawdownPct'),
          ]); ?>
        </section>

        <section class="card">
          <h2>Taxes</h2>
          <?php render_key_values([
              'Dividend Gain' => section_value($taxes, 'dividendGain'),
              'Interest Gain' => section_value($taxes, 'interestGain'),
              'Dividend Tax' => section_value($taxes, 'dividendTax'),
              'Interest Tax' => section_value($taxes, 'interestTax'),
              'Estimated Tax' => section_value($taxes, 'estimatedTax'),
          ]); ?>
        </section>

        <section class="card">
          <h2>Context</h2>
          <?php render_key_values([
              'Market Regime' => section_value($context, 'marketRegime'),
              'Volatility Level' => section_value($context, 'volatilityLevel'),
              'Reuse Score' => section_value($agentLearning, 'reuseScore'),
              'Improvement Potential' => section_value($agentLearning, 'improvementPotentialScore'),
              'Confidence Score' => section_value($agentLearning, 'confidenceScore'),
          ]); ?>
        </section>

        <section class="card">
          <h2>What Worked</h2>
          <?php render_scored_items(is_array($takeaways['worked'] ?? null) ? $takeaways['worked'] : []); ?>
        </section>

        <section class="card">
          <h2>What Did Not Work</h2>
          <?php render_scored_items(is_array($takeaways['didNotWork'] ?? null) ? $takeaways['didNotWork'] : []); ?>
        </section>

        <section class="card full">
          <h2>Next Changes</h2>
          <?php render_scored_items(is_array($takeaways['nextChanges'] ?? null) ? $takeaways['nextChanges'] : []); ?>
        </section>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
