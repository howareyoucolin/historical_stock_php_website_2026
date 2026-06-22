<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$pdo = connect_pdo($config);

$perPage = 50;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null && $page > 0 ? $page : 1;
$offset = ($page - 1) * $perPage;

$totalReports = (int) $pdo->query('SELECT COUNT(*) FROM stock_reports')->fetchColumn();
$totalPages = max(1, (int) ceil($totalReports / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare(
    <<<'SQL'
SELECT id, report_json, created_at
     , strategy_title
FROM stock_reports
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
        'summary' => "End {$endDate} / Value {$endingValue} / Return {$returnPct}%",
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Stock Reports</title>
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
    .shell {
      min-height: 100vh;
      padding: 28px 20px 40px;
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
    .layout {
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(280px, 0.9fr);
      gap: 18px;
      align-items: start;
    }
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
    .hero {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 18px;
      padding: 26px 28px 18px;
      border-bottom: 1px solid #edf0f4;
    }
    .metric-label {
      font-size: 13px;
      color: var(--muted);
      margin-bottom: 8px;
      font-weight: 600;
    }
    .metric-value {
      font-size: 34px;
      line-height: 1;
      margin-bottom: 8px;
      font-weight: 400;
    }
    .metric-note {
      color: var(--subtle);
      font-size: 13px;
    }
    .metric-note strong {
      color: var(--blue);
      font-weight: 600;
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
    .side-card {
      padding: 24px 24px 20px;
    }
    .side-title {
      font-size: 12px;
      letter-spacing: .08em;
      text-transform: uppercase;
      font-weight: 700;
      color: var(--muted);
      margin-bottom: 14px;
    }
    .side-big {
      font-size: 48px;
      font-weight: 400;
      margin-bottom: 18px;
    }
    .side-list {
      display: grid;
      gap: 12px;
      padding-top: 14px;
      border-top: 1px solid #edf0f4;
    }
    .side-item {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      font-size: 14px;
      color: var(--muted);
    }
    .side-item strong {
      color: var(--text);
      font-weight: 600;
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
    @media (max-width: 960px) {
      .layout { grid-template-columns: 1fr; }
      .hero { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
      .shell { padding: 20px 14px 32px; }
      h1 { font-size: 30px; }
      .hero { grid-template-columns: 1fr; padding: 22px 20px 14px; }
      .card-strip { margin-left: 20px; }
      .report-list { padding: 16px; }
      .title { font-size: 20px; }
    }
  </style>
</head>
<body>
  <div class="shell">
    <main>
      <div class="header">
        <h1>Home</h1>
        <p class="muted">Showing <?= h(count($reports)) ?> of <?= h($totalReports) ?> reports, newest first. Page <?= h($page) ?> of <?= h($totalPages) ?>.</p>
      </div>

      <div class="layout">
        <section class="card primary-card">
          <div class="card-strip"></div>
          <div class="hero">
            <div>
              <div class="metric-label">Stored reports</div>
              <div class="metric-value"><?= h($totalReports) ?></div>
              <div class="metric-note"><strong>Active archive</strong> of uploaded simulation summaries</div>
            </div>
            <div>
              <div class="metric-label">Reports per page</div>
              <div class="metric-value"><?= h($perPage) ?></div>
              <div class="metric-note">Simple pagination for quick scanning</div>
            </div>
            <div>
              <div class="metric-label">Current page</div>
              <div class="metric-value"><?= h($page) ?></div>
              <div class="metric-note">Sorted by <strong>created time desc</strong></div>
            </div>
            <div>
              <div class="metric-label">Latest report id</div>
              <div class="metric-value"><?= h($reports[0]['id'] ?? 0) ?></div>
              <div class="metric-note">Click any card to open full report details</div>
            </div>
          </div>

          <div class="report-list">
            <?php foreach ($reports as $row): ?>
              <?php $label = report_label($row); ?>
              <a class="row" href="/report.php?id=<?= h($row['id']) ?>">
                <div class="meta">Report #<?= h($row['id']) ?> / <?= h($row['created_at']) ?></div>
                <div class="title"><?= h($label['title']) ?></div>
                <div class="summary"><?= h($label['summary']) ?></div>
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

        <aside class="card side-card">
          <div class="side-title">Reports Snapshot</div>
          <div class="side-big"><?= h(count($reports)) ?></div>
          <div class="side-list">
            <div class="side-item"><span>Newest first</span><strong>Yes</strong></div>
            <div class="side-item"><span>Detail view</span><strong>Document format</strong></div>
            <div class="side-item"><span>Strategy title</span><strong>Saved in table</strong></div>
            <div class="side-item"><span>Storage links</span><strong>Per report id</strong></div>
          </div>
        </aside>
      </div>
    </main>
  </div>
</body>
</html>
