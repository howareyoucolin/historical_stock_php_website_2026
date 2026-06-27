<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/support/stock_symbol_utils.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: text/plain; charset=utf-8');

    try {
        $symbol = strtoupper(trim((string) ($argv[1] ?? 'AAPL')));
        $startDate = trim((string) ($argv[2] ?? '2001-01-01'));
        $endDate = trim((string) ($argv[3] ?? date('Y-m-d')));

        if ($symbol === '') {
            throw new RuntimeException('Symbol is required.');
        }

        $start = parse_date($startDate, false);
        $end = parse_date($endDate, true);

        if ($end < $start) {
            throw new RuntimeException('End date must be on or after start date.');
        }

        $pdo = connect_pdo();
        $stock = find_stock($pdo, $symbol);

        if ($stock === null) {
            throw new RuntimeException("Symbol {$symbol} was not found in stocks.");
        }

        $result = import_stock_daily_prices($pdo, $stock, $start, $end);

        echo "Imported {$stock['symbol']} daily prices.\n";
        echo 'Lookup symbol: ' . $symbol . "\n";
        echo 'Yahoo symbol: ' . $result['fetch_symbol'] . "\n";
        echo 'Stored under: ' . $result['target_symbol'] . " (#{$result['target_stock_id']})\n";
        echo 'Date range: ' . $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d') . "\n";
        echo 'Rows fetched: ' . $result['rows_fetched'] . "\n";
        echo 'Rows inserted/updated: ' . $result['rows_written'] . "\n";
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Import failed: ' . $e->getMessage() . "\n";
        exit(1);
    }
}

function import_stock_daily_prices(PDO $pdo, array $stock, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $fetchSymbol = resolve_price_symbol((string) $stock['symbol']);
    $rows = fetch_yahoo_daily_prices($fetchSymbol, $start, $end);

    if ($rows === []) {
        return [
            'fetch_symbol' => $fetchSymbol,
            'rows_fetched' => 0,
            'rows_written' => 0,
        ];
    }

    $stmt = $pdo->prepare(
        <<<'SQL'
INSERT INTO stock_daily_prices (
    stock_id,
    trade_date,
    close,
    adj_close,
    volume
) VALUES (
    :stock_id,
    :trade_date,
    :close,
    :adj_close,
    :volume
)
ON DUPLICATE KEY UPDATE
    close = VALUES(close),
    adj_close = VALUES(adj_close),
    volume = VALUES(volume),
    updated_at = CURRENT_TIMESTAMP
SQL
    );

    $rowsWritten = 0;

    foreach ($rows as $row) {
        $stmt->execute([
            'stock_id' => $stock['id'],
            'trade_date' => $row['trade_date'],
            'close' => $row['close'],
            'adj_close' => $row['adj_close'],
            'volume' => $row['volume'],
        ]);

        $rowsWritten += $stmt->rowCount();
    }

    return [
        'fetch_symbol' => $fetchSymbol,
        'rows_fetched' => count($rows),
        'rows_written' => $rowsWritten,
        'target_stock_id' => $stock['id'],
        'target_symbol' => $stock['symbol'],
    ];
}

function parse_date(string $value, bool $endOfDay): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value, new DateTimeZone('America/New_York'));
    if (!$date instanceof DateTimeImmutable) {
        throw new RuntimeException("Invalid date: {$value}. Expected YYYY-MM-DD.");
    }

    return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
}

function find_stock(PDO $pdo, string $symbol): ?array
{
    $lookupSymbol = normalize_stock_symbol($symbol);
    $stmt = $pdo->prepare('SELECT id, symbol FROM stocks WHERE symbol = :symbol LIMIT 1');
    $stmt->execute(['symbol' => $lookupSymbol]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($row)) {
        return $row;
    }

    $resolvedSymbol = resolve_price_symbol($lookupSymbol);
    if ($resolvedSymbol === '' || $resolvedSymbol === $lookupSymbol) {
        return null;
    }

    $stmt->execute(['symbol' => $resolvedSymbol]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * Yahoo's chart API now gates most symbols behind a cookie + crumb session.
 * Without a valid crumb, even live tickers return "No data found, symbol may be
 * delisted", so we establish a session once and reuse it for every request.
 * Returns ['cookie' => path, 'crumb' => string, 'ua' => string]; crumb may be
 * empty if Yahoo is currently rate-limiting the crumb endpoint.
 */
function yahoo_session(bool $refresh = false): array
{
    static $session = null;

    if (is_array($session) && !$refresh) {
        return $session;
    }

    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    $cookieFile = is_array($session) ? (string) $session['cookie'] : (string) tempnam(sys_get_temp_dir(), 'yh_');

    $crumb = '';
    for ($attempt = 1; $attempt <= 6; $attempt++) {
        // Prime cookies (A1/A3) from the consent-free landing page.
        yahoo_curl_get('https://finance.yahoo.com/', $cookieFile, $ua);
        [$body, $code] = yahoo_curl_get('https://query2.finance.yahoo.com/v1/test/getcrumb', $cookieFile, $ua);
        $candidate = trim((string) $body);

        if ($code === 200 && $candidate !== '' && strlen($candidate) < 40 && stripos($candidate, 'too many') === false) {
            $crumb = $candidate;
            break;
        }

        // Rate limited or empty — back off and retry.
        sleep(min(20, 3 * $attempt));
    }

    $session = ['cookie' => $cookieFile, 'crumb' => $crumb, 'ua' => $ua];

    return $session;
}

/**
 * @return array{0: string|null, 1: int} response body (null on transport error) and HTTP status.
 */
function yahoo_curl_get(string $url, string $cookieFile, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain,*/*'],
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$body === false ? null : (string) $body, $code];
}

function fetch_yahoo_daily_prices(string $symbol, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    // Yahoo represents share classes with a dash (e.g. BRK.B -> BRK-B).
    $yahooSymbol = str_replace('.', '-', $symbol);

    $attempt = 0;
    $lastError = null;
    $refreshedSession = false;

    while ($attempt < 5) {
        $attempt++;

        $session = yahoo_session();
        $crumb = (string) $session['crumb'];

        $url = sprintf(
            'https://query2.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d&includePrePost=false&events=div%%2Csplits&includeAdjustedClose=true',
            rawurlencode($yahooSymbol),
            $start->getTimestamp(),
            $end->getTimestamp()
        );
        if ($crumb !== '') {
            $url .= '&crumb=' . rawurlencode($crumb);
        }

        [$raw, $code] = yahoo_curl_get($url, (string) $session['cookie'], (string) $session['ua']);

        // Rate limited — back off, and refresh the session once in case the
        // crumb/cookie went stale.
        if ($code === 429 || ($raw !== null && stripos($raw, 'too many requests') !== false)) {
            $lastError = "Yahoo rate limited {$yahooSymbol} (HTTP {$code}).";
            sleep(min(30, 4 * $attempt));
            if (!$refreshedSession) {
                yahoo_session(true);
                $refreshedSession = true;
            }
            continue;
        }

        if ($raw === null) {
            $lastError = "Yahoo request failed for {$yahooSymbol}.";
            usleep($attempt * 400000);
            continue;
        }

        // Crumb rejected — refresh the session once and retry.
        if ($code === 401 && !$refreshedSession) {
            yahoo_session(true);
            $refreshedSession = true;
            $lastError = "Yahoo unauthorized for {$yahooSymbol} (refreshing crumb).";
            continue;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $lastError = "Yahoo returned invalid JSON for {$yahooSymbol}.";
            usleep($attempt * 400000);
            continue;
        }

        $result = $decoded['chart']['result'][0] ?? null;
        $error = $decoded['chart']['error'] ?? null;

        if (!is_array($result)) {
            $message = is_array($error) ? (string) ($error['description'] ?? 'Unknown Yahoo error.') : 'Missing chart result.';
            $isDelisted = str_contains(strtolower($message), 'not found') || str_contains(strtolower($message), 'delisted');

            // A "delisted" verdict is only trustworthy when we actually sent a
            // valid crumb; otherwise Yahoo returns it for live tickers too.
            if ($isDelisted && $crumb !== '') {
                throw new RuntimeException("Yahoo chart error for {$yahooSymbol}: {$message}");
            }

            if ($isDelisted && $crumb === '' && !$refreshedSession) {
                yahoo_session(true);
                $refreshedSession = true;
            }

            $lastError = "Yahoo chart error for {$yahooSymbol}: {$message}";
            usleep($attempt * 400000);
            continue;
        }

        $timestamps = is_array($result['timestamp'] ?? null) ? $result['timestamp'] : [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $adjclose = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

        $closes = is_array($quote['close'] ?? null) ? $quote['close'] : [];
        $volumes = is_array($quote['volume'] ?? null) ? $quote['volume'] : [];
        $adjcloses = is_array($adjclose) ? $adjclose : [];

        $rows = [];

        foreach ($timestamps as $index => $timestamp) {
            $close = $closes[$index] ?? null;
            if ($close === null) {
                continue;
            }

            $tradeDate = (new DateTimeImmutable('@' . (int) $timestamp))
                ->setTimezone(new DateTimeZone('America/New_York'))
                ->format('Y-m-d');

            $adj = $adjcloses[$index] ?? null;
            $volume = $volumes[$index] ?? null;

            $rows[] = [
                'trade_date' => $tradeDate,
                'close' => round((float) $close, 4),
                'adj_close' => $adj === null ? null : round((float) $adj, 4),
                'volume' => $volume === null ? null : max(0, (int) $volume),
            ];
        }

        return $rows;
    }

    throw new RuntimeException($lastError ?? "Yahoo request failed for {$yahooSymbol}.");
}

function connect_pdo(): PDO
{
    $dsn = getenv('DB_DSN');
    if ($dsn !== false && $dsn !== '') {
        $username = getenv('DB_USERNAME') ?: null;
        $password = getenv('DB_PASSWORD') ?: null;

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_DATABASE') ?: '';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    if ($database !== '') {
        $mysqlDsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        return new PDO($mysqlDsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    $configFile = dirname(__DIR__, 2) . '/public/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Missing config.php.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.');
    }

    $db = is_array($config['db'] ?? null) ? $config['db'] : [];
    $configHost = (string) ($db['host'] ?? '');
    $configDatabase = (string) ($db['database'] ?? '');
    $configUsername = (string) ($db['username'] ?? '');
    $configPassword = (string) ($db['password'] ?? '');
    $configCharset = (string) ($db['charset'] ?? 'utf8mb4');
    $configPort = (string) ($db['port'] ?? '');

    if ($configHost === '' || $configDatabase === '' || $configUsername === '') {
        throw new RuntimeException('Database config is incomplete.');
    }

    $mysqlDsn = "mysql:host={$configHost};dbname={$configDatabase};charset={$configCharset}";
    if ($configPort !== '') {
        $mysqlDsn = "mysql:host={$configHost};port={$configPort};dbname={$configDatabase};charset={$configCharset}";
    }

    return new PDO($mysqlDsn, $configUsername, $configPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
