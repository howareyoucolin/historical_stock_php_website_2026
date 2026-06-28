<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$pdo = connect_pdo($config);

$perPage = 50;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null && $page > 0 ? $page : 1;
$offset = ($page - 1) * $perPage;

$totalReports = (int) $pdo->query('SELECT COUNT(*) FROM reports')->fetchColumn();
$totalPages = max(1, (int) ceil($totalReports / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare(
    <<<'SQL'
SELECT reports.id,
       reports.report_json,
       reports.created_at,
       reports.strategy_title,
       reports.version,
       report_uploaders.uploader AS updated_by_name
FROM reports
LEFT JOIN report_uploaders
  ON report_uploaders.id = reports.updated_by
ORDER BY created_at DESC, id DESC
LIMIT :limit OFFSET :offset
SQL
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

function format_display_value(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return number_format((float) $value, is_float($value) ? 2 : 0, '.', ',');
    }

    $stringValue = trim((string) $value);
    if ($stringValue === '' || !is_numeric($stringValue)) {
        return (string) $value;
    }

    $number = (float) $stringValue;
    $decimals = str_contains($stringValue, '.') ? strlen(rtrim(substr(strrchr($stringValue, '.'), 1), '0')) : 0;

    return number_format($number, $decimals, '.', ',');
}

function report_label(array $row): array
{
    $decoded = json_decode((string) $row['report_json'], true);
    if (!is_array($decoded)) {
        return [
            'title' => (string) ($row['strategy_title'] ?? ('Report #' . $row['id'])),
            'summary' => 'Unreadable report JSON',
        ];
    }

    $objective = is_array($decoded['objective'] ?? null) ? $decoded['objective'] : [];
    $simulation = is_array($decoded['simulation'] ?? null) ? $decoded['simulation'] : [];

    $title = trim((string) ($row['strategy_title'] ?? ''));
    if ($title === '') {
        $title = (string) ($objective['title'] ?? 'Simulation Report');
    }
    $endDate = (string) ($simulation['simEndDate'] ?? '-');
    $endingValue = format_display_value($simulation['endingValue'] ?? '-');
    $returnPct = format_display_value($simulation['totalReturnPct'] ?? '-');

    return [
        'title' => $title,
        'endDate' => $endDate,
        'endingValue' => $endingValue,
        'returnPct' => $returnPct,
    ];
}

$pageTitle = 'Stock Simulation Reports Archive';
$pageDescription = 'Browse saved stock simulation reports, review portfolio outcomes, and open detailed report summaries with performance, risk, and positions data.';
$pageKeywords = 'stock reports, simulation reports, portfolio summary, trading simulation, investment report, stock portfolio, backtest archive';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <meta name="description" content="<?= h($pageDescription) ?>">
  <meta name="keywords" content="<?= h($pageKeywords) ?>">
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
    .page-banner {
      background:
        radial-gradient(circle at top left, rgba(26, 115, 232, 0.18), transparent 38%),
        linear-gradient(135deg, #eef4ff 0%, #f8fbff 46%, #f4f7fc 100%);
      border-bottom: 1px solid rgba(26, 115, 232, 0.10);
      box-shadow: inset 0 -1px 0 rgba(255, 255, 255, 0.7);
    }
    .page-banner-inner {
      max-width: 1180px;
      margin: 0 auto;
      padding: 34px 20px 30px;
    }
    .banner-kicker {
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
    .banner-copy {
      max-width: 760px;
    }
    .shell {
      min-height: 100vh;
      padding: 24px 20px 40px;
    }
    main {
      max-width: 1180px;
      margin: 0 auto;
    }
    .header {
      margin-bottom: 22px;
    }
    h1 {
      margin: 0 0 8px;
      font-size: 36px;
      font-weight: 500;
      letter-spacing: -0.02em;
    }
    p {
      margin: 0;
      line-height: 1.5;
      font-size: 14px;
    }
    .muted { color: var(--muted); }
    .card {
      background: var(--panel);
      border: 1px solid var(--panel-border);
      border-radius: 14px;
      box-shadow: var(--shadow);
    }
    .primary-card {
      padding: 0;
      overflow: hidden;
    }
    .card-strip {
      height: 4px;
      width: 120px;
      background: var(--blue);
      border-radius: 0 0 8px 8px;
      margin-left: 32px;
    }
    .report-list {
      display: grid;
      gap: 12px;
      padding: 20px;
    }
    .row {
      display: block;
      padding: 18px 18px 16px;
      border: 1px solid #e6e9ef;
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
      text-decoration: none;
      color: inherit;
      transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }
    .row:hover {
      border-color: #c6dafc;
      box-shadow: 0 4px 18px rgba(26, 115, 232, 0.10);
      transform: translateY(-1px);
    }
    .meta {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .08em;
      color: var(--subtle);
      margin-bottom: 10px;
      font-weight: 700;
    }
    .title {
      font-size: 24px;
      line-height: 1.25;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text);
    }
    .summary {
      color: var(--muted);
      font-size: 14px;
    }
    .summary .figure {
      color: #137333;
      font-weight: 700;
    }
    .pager {
      display: flex;
      gap: 14px;
      align-items: center;
      padding: 0 20px 20px;
    }
    .pager a {
      color: var(--blue);
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
    }
    .site-footer {
      margin-top: 22px;
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
    @media (max-width: 640px) {
      .page-banner-inner { padding: 26px 14px 24px; }
      .shell { padding: 20px 14px 32px; }
      h1 { font-size: 30px; }
      .card-strip { margin-left: 20px; }
      .report-list { padding: 16px; }
      .title { font-size: 20px; }
    }
  </style>
</head>
<body>
  <div class="page-banner">
    <div class="page-banner-inner">
      <span class="banner-kicker">Stock Report Archive</span>
      <div class="header banner-copy">
        <h1>Simulation Reports</h1>
        <p class="muted">Showing <?= h(count($reports)) ?> of <?= h($totalReports) ?> reports, newest first. Page <?= h($page) ?> of <?= h($totalPages) ?>.</p>
      </div>
    </div>
  </div>
  <div class="shell">
    <main>
      <section class="card primary-card">
        <div class="card-strip"></div>
        <div class="report-list">
          <?php foreach ($reports as $row): ?>
            <?php $label = report_label($row); ?>
            <a class="row" href="/report.php?id=<?= h($row['id']) ?>">
              <div class="meta">
                Report #<?= h($row['id']) ?> / <?= h($row['created_at']) ?> / v<?= h($row['version']) ?>
                <?php if (!empty($row['updated_by_name'])): ?>
                  / Updated by <?= h($row['updated_by_name']) ?>
                <?php endif; ?>
              </div>
              <div class="title"><?= h($label['title']) ?></div>
              <div class="summary">End <?= h($label['endDate']) ?> / Value <span class="figure"><?= h($label['endingValue']) ?></span> / Return <span class="figure"><?= h($label['returnPct']) ?>%</span></div>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="pager">
          <?php if ($page > 1): ?>
            <a href="/index.php?page=<?= h($page - 1) ?>">Newer</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a href="/index.php?page=<?= h($page + 1) ?>">Older</a>
          <?php endif; ?>
        </div>
      </section>
      <?php require __DIR__ . '/footer.php'; ?>
    </main>
  </div>
</body>
</html>
