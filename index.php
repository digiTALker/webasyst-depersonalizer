<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/src/StandaloneConfigLoader.php';
require_once __DIR__ . '/src/StandaloneDepersonalizer.php';

/**
 * @param array<string, mixed> $payload
 * @param int $statusCode
 */
function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readPostInt(string $name, int $default): int
{
    if (!isset($_POST[$name])) {
        return $default;
    }
    return (int)$_POST[$name];
}

function readPostBool(string $name): bool
{
    if (!isset($_POST[$name])) {
        return false;
    }
    $value = (string)$_POST[$name];
    return in_array($value, array('1', 'true', 'on', 'yes'), true);
}

function depersonalizerEnv(string $name): string
{
    $value = getenv($name);
    return $value === false ? '' : trim((string)$value);
}

/**
 * @param array<string, mixed> $config
 */
function depersonalizerAccessConfigured(array $config): bool
{
    return depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN') !== ''
        || depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN_HASH') !== ''
        || depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN_SHA256') !== ''
        || trim((string)($config['access_token'] ?? '')) !== ''
        || trim((string)($config['access_token_hash'] ?? '')) !== ''
        || trim((string)($config['access_token_sha256'] ?? '')) !== '';
}

/**
 * @param array<string, mixed> $config
 */
function depersonalizerVerifyAccessToken(string $token, array $config): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $plainToken = depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN');
    if ($plainToken === '') {
        $plainToken = trim((string)($config['access_token'] ?? ''));
    }
    if ($plainToken !== '' && hash_equals($plainToken, $token)) {
        return true;
    }

    $sha256 = depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN_SHA256');
    if ($sha256 === '') {
        $sha256 = trim((string)($config['access_token_sha256'] ?? ''));
    }
    $sha256 = strtolower($sha256);
    if ($sha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
        return hash_equals($sha256, hash('sha256', $token));
    }

    $passwordHash = depersonalizerEnv('DEPERSONALIZER_ACCESS_TOKEN_HASH');
    if ($passwordHash === '') {
        $passwordHash = trim((string)($config['access_token_hash'] ?? ''));
    }
    if ($passwordHash !== '') {
        return password_verify($token, $passwordHash);
    }

    return false;
}

function depersonalizerBearerToken(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    return '';
}

/**
 * @param array<string, mixed> $config
 */
function depersonalizerIsAuthorized(array $config): bool
{
    if (!empty($_SESSION['depersonalizer_authenticated'])) {
        return true;
    }

    $bearerToken = depersonalizerBearerToken();
    if ($bearerToken !== '' && depersonalizerVerifyAccessToken($bearerToken, $config)) {
        return true;
    }

    $basicPassword = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($basicPassword !== '' && depersonalizerVerifyAccessToken($basicPassword, $config)) {
        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function depersonalizerDetectEnvironment(array $config): array
{
    $database = strtolower(trim((string)($config['database'] ?? '')));
    $host = strtolower(trim((string)($config['host'] ?? '')));
    $scriptPath = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? '')));
    $requestUri = strtolower((string)($_SERVER['REQUEST_URI'] ?? ''));

    $looksTest = strpos($database, '_test_') !== false
        || strpos($scriptPath, '/test/') !== false
        || strpos($requestUri, '/test/') !== false
        || strpos($host, 'test') !== false;

    $looksProd = $database === 'dtalke_shop' || !$looksTest;
    $allowProd = !empty($config['allow_prod']);

    return array(
        'label' => $looksProd ? 'PROD' : 'TEST',
        'is_prod' => $looksProd,
        'allow_prod' => $allowProd,
        'can_run_real' => !$looksProd || $allowProd,
    );
}

function depersonalizerRedirectBack(): void
{
    $target = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), "\r\n");
    if ($target === false || $target === '') {
        $target = (string)($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    }

    header('Location: ' . $target);
    exit;
}

function renderAccessPage(bool $isConfigured, ?string $error = null): void
{
    http_response_code($isConfigured ? 401 : 503);
    $safeError = $error !== null ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Webasyst Depersonalizer Access</title>
    <style>
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: #f5f6f8;
            color: #1f2937;
        }

        .box {
            max-width: 520px;
            margin: 64px auto;
            padding: 24px;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 10px;
        }

        label {
            display: block;
            margin: 16px 0 6px;
            font-size: 13px;
            color: #6b7280;
        }

        input[type="password"] {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
        }

        button {
            margin-top: 14px;
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            color: #fff;
            background: #005fb8;
            font-weight: 600;
            cursor: pointer;
        }

        .error {
            margin-top: 12px;
            color: #b42318;
        }

        code {
            background: #eef2f7;
            border-radius: 6px;
            padding: 2px 6px;
        }
    </style>
</head>
<body>
<div class="box">
    <h1>Access required</h1>
    <?php if (!$isConfigured): ?>
        <p>This tool is locked until an access token is configured.</p>
        <p>Set <code>DEPERSONALIZER_ACCESS_TOKEN</code> in the server environment or add <code>access_token</code> to <code>config.local.php</code>.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <label for="access_token">Access token</label>
            <input id="access_token" name="access_token" type="password" autocomplete="current-password" autofocus>
            <button type="submit">Unlock</button>
        </form>
        <?php if ($safeError !== ''): ?>
            <div class="error"><?php echo $safeError; ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    exit;
}

$dbConfig = StandaloneConfigLoader::load(__DIR__);
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestAction = (string)($_POST['action'] ?? '');
$accessConfigured = depersonalizerAccessConfigured($dbConfig);

if ($requestMethod === 'POST' && $requestAction === 'login') {
    if (!$accessConfigured) {
        renderAccessPage(false);
    }

    if (depersonalizerVerifyAccessToken((string)($_POST['access_token'] ?? ''), $dbConfig)) {
        session_regenerate_id(true);
        $_SESSION['depersonalizer_authenticated'] = true;
        depersonalizerRedirectBack();
    }

    renderAccessPage(true, 'Invalid access token.');
}

if ($requestMethod === 'POST' && $requestAction === 'logout') {
    unset($_SESSION['depersonalizer_authenticated'], $_SESSION['depersonalizer_csrf']);
    session_regenerate_id(true);
    depersonalizerRedirectBack();
}

if (!$accessConfigured) {
    if ($requestMethod === 'POST') {
        jsonResponse(array('ok' => false, 'error' => 'Access token is not configured.'), 503);
    }
    renderAccessPage(false);
}

if (!depersonalizerIsAuthorized($dbConfig)) {
    if ($requestMethod === 'POST') {
        jsonResponse(array('ok' => false, 'error' => 'Unauthorized.'), 401);
    }
    renderAccessPage(true);
}

if (empty($_SESSION['depersonalizer_csrf'])) {
    $_SESSION['depersonalizer_csrf'] = bin2hex(random_bytes(24));
}

$csrfToken = (string)$_SESSION['depersonalizer_csrf'];

$service = null;
$preflight = null;
$configError = null;
$dbConnectionError = null;
$schemaError = null;
$mysqlVersion = 'n/a';
$environment = depersonalizerDetectEnvironment($dbConfig);

$missingConfigKeys = StandaloneConfigLoader::missingRequiredKeys($dbConfig);
if ($missingConfigKeys) {
    $source = (string)($dbConfig['__source'] ?? 'none');
    $configError = 'Loaded config source: ' . ($source !== '' ? $source : 'none') .
        '. Missing required key(s): ' . implode(', ', $missingConfigKeys) . '.';
} else {
    try {
        $dsn = StandaloneConfigLoader::buildDsn($dbConfig);

        $pdo = new PDO(
            $dsn,
            (string)$dbConfig['user'],
            (string)$dbConfig['password'],
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            )
        );

        $service = new StandaloneDepersonalizer($pdo, __DIR__ . DIRECTORY_SEPARATOR . 'logs');
        $mysqlVersion = $service->getMysqlVersion();
        $setupStateTable = $requestMethod === 'GET';
        $setupStateTable = $setupStateTable
            && (empty($environment['is_prod']) || !empty($environment['allow_prod']));
        $preflight = $service->preflight($setupStateTable);

        if (!empty($preflight['missing_required_tables'])) {
            $schemaError = 'Missing required tables: ' . implode(', ', $preflight['missing_required_tables']);
        }
    } catch (PDOException $error) {
        $dbConnectionError = $error->getMessage();
    } catch (Throwable $error) {
        if ($service instanceof StandaloneDepersonalizer) {
            $schemaError = $error->getMessage();
        } else {
            $configError = $error->getMessage();
        }
    }
}

$pageReady = $service instanceof StandaloneDepersonalizer
    && $configError === null
    && $dbConnectionError === null
    && $schemaError === null;

if ($requestMethod === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!in_array($action, array('preview', 'run'), true)) {
        jsonResponse(array('ok' => false, 'error' => 'Unsupported action.'), 400);
    }

    $incomingToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $incomingToken)) {
        jsonResponse(array('ok' => false, 'error' => 'CSRF token mismatch.'), 403);
    }

    if (!$pageReady) {
        $kind = 'schema';
        $message = (string)$schemaError;
        if ($configError !== null) {
            $kind = 'config';
            $message = $configError;
        } elseif ($dbConnectionError !== null) {
            $kind = 'connection';
            $message = $dbConnectionError;
        }
        jsonResponse(array('ok' => false, 'error' => $kind . ' error: ' . $message), 500);
    }

    try {
        if ($action === 'preview') {
            $days = readPostInt('days', 365);
            $preview = $service->preview($days);
            jsonResponse(array('ok' => true, 'data' => $preview));
        }

        $options = array(
            'days' => readPostInt('days', 365),
            'limit' => readPostInt('limit', 200),
            'cursor' => readPostInt('cursor', 0),
            'keep_geo' => readPostBool('keep_geo'),
            'wipe_comments' => readPostBool('wipe_comments'),
            'anonymize_contacts' => readPostBool('anonymize_contacts'),
            'dry_run' => readPostBool('dry_run'),
            'backup_confirmed' => readPostBool('backup_confirmed'),
            'confirmation_phrase' => (string)($_POST['confirmation_phrase'] ?? ''),
            'include_keys' => isset($_POST['include_keys']) && is_array($_POST['include_keys'])
                ? array_values($_POST['include_keys'])
                : array(),
        );

        if (empty($options['dry_run'])) {
            if (empty($environment['can_run_real'])) {
                jsonResponse(array(
                    'ok' => false,
                    'error' => 'Production-like environment is blocked. Set allow_prod => true in config.local.php only after verifying the target DB.',
                ), 403);
            }
            if (empty($options['backup_confirmed']) || trim((string)$options['confirmation_phrase']) !== 'ANONYMIZE') {
                jsonResponse(array(
                    'ok' => false,
                    'error' => 'Real anonymization requires a fresh backup checkbox and exact ANONYMIZE confirmation.',
                ), 400);
            }
        }

        $result = $service->runBatch($options);
        jsonResponse(array('ok' => true, 'data' => $result));
    } catch (Throwable $error) {
        jsonResponse(array('ok' => false, 'error' => $error->getMessage()), 500);
    }
}

$optionalTables = array(
    'wa_contact' => false,
    'wa_contact_emails' => false,
    'wa_contact_data' => false,
    'wa_contact_data_text' => false,
    'wa_contact_addresses' => false,
    'wa_contact_params' => false,
    'state_table' => 'depersonalizer_state',
    'state_table_ready' => false,
    'state_table_error' => null,
);
if (is_array($preflight) && isset($preflight['optional_tables']) && is_array($preflight['optional_tables'])) {
    $optionalTables = $preflight['optional_tables'];
}

$safeModeNotes = array();
if (is_array($preflight) && isset($preflight['safe_mode_notes']) && is_array($preflight['safe_mode_notes'])) {
    $safeModeNotes = $preflight['safe_mode_notes'];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Webasyst Depersonalizer (Standalone)</title>
    <style>
        :root {
            --bg: #f5f6f8;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #d1d5db;
            --primary: #005fb8;
            --danger: #b42318;
            --ok: #027a48;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: radial-gradient(circle at 10% 10%, #ffffff 0%, var(--bg) 55%);
            color: var(--text);
        }

        .container {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 16px;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: 0 4px 14px rgba(17, 24, 39, 0.06);
        }

        .error {
            border-color: #f1a7a1;
            background: #fff5f5;
            color: #7a271a;
        }

        .warning {
            border-color: #f3d087;
            background: #fff9ec;
            color: #7a4f00;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 6px 14px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0;
        }

        .badge-test {
            color: #064e3b;
            background: #d1fae5;
            border: 1px solid #34d399;
        }

        .badge-prod {
            color: #7a271a;
            background: #fee4e2;
            border: 1px solid #f97066;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }

        label {
            display: block;
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        input[type="number"], input[type="text"] {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }

        .checkbox-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 8px;
        }

        .checkbox-row label {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            margin: 0;
            color: var(--text);
            font-size: 14px;
        }

        .protection {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.12s ease, opacity 0.12s ease;
        }

        button:disabled {
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
        }

        .btn-primary {
            color: #fff;
            background: var(--primary);
        }

        .btn-ghost {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .progress-wrap {
            border: 1px solid var(--border);
            border-radius: 999px;
            overflow: hidden;
            height: 16px;
            margin-top: 10px;
            background: #eef2f7;
        }

        .progress-bar {
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, #0a84ff 0%, #2ec4b6 100%);
            transition: width 0.2s ease;
        }

        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 12px;
            line-height: 1.45;
            white-space: pre-wrap;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            margin-top: 12px;
            max-height: 300px;
            overflow: auto;
        }

        .klist {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 8px;
            margin-top: 8px;
        }

        .kitem {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            background: #fbfcff;
        }

        .status {
            margin-top: 10px;
            font-size: 14px;
        }

        .status.ok {
            color: var(--ok);
        }

        .status.err {
            color: var(--danger);
        }

        ul {
            margin: 8px 0 0 18px;
        }

        code {
            background: #eef2f7;
            border-radius: 6px;
            padding: 2px 6px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Webasyst Depersonalizer</h1>
            <p class="subtitle">Standalone page. No plugin installation required.</p>
        </div>
        <div class="card" style="min-width: 320px; margin: 0;">
            <div style="margin-bottom: 8px;">
                <span class="badge <?php echo !empty($environment['is_prod']) ? 'badge-prod' : 'badge-test'; ?>">
                    <?php echo htmlspecialchars((string)$environment['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if (!empty($environment['is_prod']) && empty($environment['allow_prod'])): ?>
                    <span style="margin-left: 8px; color: var(--danger); font-weight: 600;">real run blocked</span>
                <?php endif; ?>
            </div>
            <div><strong>Connection source:</strong> <code><?php echo htmlspecialchars((string)($dbConfig['__source'] ?? 'config.local.php / manual'), ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>DB:</strong> <code><?php echo htmlspecialchars((string)$dbConfig['database'], ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>MySQL:</strong> <code><?php echo htmlspecialchars($mysqlVersion, ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>PHP:</strong> <code><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>Schema:</strong> <code><?php echo $pageReady ? 'compatible' : 'not ready'; ?></code></div>
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="action" value="logout">
                <button class="btn-ghost" type="submit">Lock</button>
            </form>
        </div>
    </div>

    <?php if ($configError !== null): ?>
        <div class="card error">
            <strong>Config error:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($configError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Use the flat <code>config.local.php</code> format from <code>config.local.php.example</code>. Password is never printed here.</div>
        </div>
    <?php endif; ?>

    <?php if ($dbConnectionError !== null): ?>
        <div class="card error">
            <strong>DB connection error:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($dbConnectionError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">The config was loaded, but PDO could not connect to MySQL.</div>
        </div>
    <?php endif; ?>

    <?php if ($schemaError !== null): ?>
        <div class="card error">
            <strong>Schema/preflight error:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($schemaError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Core order workflow requires only <code>shop_order</code> and <code>shop_order_params</code>. Optional contact tables are not required for page load.</div>
        </div>
    <?php endif; ?>

    <?php if ($pageReady): ?>
        <div class="card warning">
            <strong>Safe-mode behavior (to avoid Webasyst core conflicts)</strong>
            <ul>
                <?php foreach ($safeModeNotes as $line): ?>
                    <li><?php echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top: 8px;">
                Schema compatibility:
                <code>wa_contact=<?php echo !empty($optionalTables['wa_contact']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_emails=<?php echo !empty($optionalTables['wa_contact_emails']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_data=<?php echo !empty($optionalTables['wa_contact_data']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_data_text=<?php echo !empty($optionalTables['wa_contact_data_text']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_addresses=<?php echo !empty($optionalTables['wa_contact_addresses']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_params=<?php echo !empty($optionalTables['wa_contact_params']) ? 'yes' : 'no'; ?></code>,
                <code>state=<?php echo !empty($optionalTables['state_table_ready']) ? 'ready' : 'not ready'; ?></code>
            </div>
            <?php if (empty($optionalTables['wa_contact_params'])): ?>
                <div style="margin-top: 8px;">wa_contact_params is not present. Contact processing will use standalone state tracking in <code>depersonalizer_state</code>.</div>
            <?php endif; ?>
            <?php if (empty($optionalTables['state_table_ready']) && !empty($optionalTables['state_table_error'])): ?>
                <div style="margin-top: 8px;">State table issue: <?php echo htmlspecialchars((string)$optionalTables['state_table_error'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($environment['is_prod']) && empty($environment['allow_prod'])): ?>
            <div class="card error">
                <strong>Production-like target detected.</strong>
                <div style="margin-top: 6px;">Preview and dry-run are available, but real anonymization is blocked until <code>'allow_prod' =&gt; true</code> is set intentionally in local config.</div>
            </div>
        <?php endif; ?>

        <div class="card">
            <form id="mainForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="grid">
                    <div>
                        <label for="days">Retention days</label>
                        <input id="days" name="days" type="number" value="365" min="1" max="36500">
                    </div>
                    <div>
                        <label for="limit">Batch size</label>
                        <input id="limit" name="limit" type="number" value="200" min="1" max="1000">
                    </div>
                    <div>
                        <label>Order mark / contact state</label>
                        <input type="text" value="_depersonalizer_ext_processed / depersonalizer_state" readonly>
                    </div>
                </div>

                <div class="checkbox-row">
                    <label><input type="checkbox" name="keep_geo" value="1" checked> Keep geo snapshot in <code>geo_*</code></label>
                    <label><input type="checkbox" name="wipe_comments" value="1"> Wipe order comments</label>
                    <label><input type="checkbox" name="anonymize_contacts" value="1" <?php echo empty($optionalTables['wa_contact']) || empty($optionalTables['state_table_ready']) ? 'disabled' : ''; ?>> Anonymize contacts without newer orders</label>
                    <label><input type="checkbox" name="dry_run" value="1" checked> Dry-run (no writes)</label>
                </div>

                <div class="protection">
                    <div class="checkbox-row">
                        <label><input type="checkbox" name="backup_confirmed" value="1"> I have a fresh database backup</label>
                    </div>
                    <div style="max-width: 320px; margin-top: 10px;">
                        <label for="confirmation_phrase">Type ANONYMIZE for real run</label>
                        <input id="confirmation_phrase" name="confirmation_phrase" type="text" value="" autocomplete="off">
                    </div>
                </div>

                <div class="actions">
                    <button class="btn-ghost" type="button" id="previewBtn">Preview</button>
                    <button class="btn-primary" type="button" id="runBtn">Run</button>
                    <button class="btn-ghost" type="button" id="stopBtn" disabled>Stop</button>
                </div>

                <div id="previewBlock" style="display:none; margin-top: 16px;">
                    <strong>Candidate keys</strong>
                    <div id="previewInfo" class="status"></div>
                    <div id="keysList" class="klist"></div>
                </div>
            </form>
        </div>

        <div class="card">
            <strong>Progress</strong>
            <div class="progress-wrap">
                <div id="progressBar" class="progress-bar"></div>
            </div>
            <div id="progressText" class="status">Idle</div>
            <div id="log" class="mono"></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($pageReady): ?>
<script>
(function () {
    const form = document.getElementById('mainForm');
    const previewBtn = document.getElementById('previewBtn');
    const runBtn = document.getElementById('runBtn');
    const stopBtn = document.getElementById('stopBtn');
    const previewBlock = document.getElementById('previewBlock');
    const previewInfo = document.getElementById('previewInfo');
    const keysList = document.getElementById('keysList');

    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const logNode = document.getElementById('log');
    const canRunReal = <?php echo !empty($environment['can_run_real']) ? 'true' : 'false'; ?>;

    let stopRequested = false;

    function setBusy(isBusy) {
        previewBtn.disabled = isBusy;
        runBtn.disabled = isBusy;
        stopBtn.disabled = !isBusy;
    }

    function appendLog(line) {
        const stamp = new Date().toISOString();
        logNode.textContent += '[' + stamp + '] ' + line + '\n';
        logNode.scrollTop = logNode.scrollHeight;
    }

    function collectFormState() {
        const fd = new FormData(form);
        const out = {
            csrf_token: fd.get('csrf_token') || '',
            days: fd.get('days') || '365',
            limit: fd.get('limit') || '200',
            keep_geo: fd.get('keep_geo') ? '1' : '0',
            wipe_comments: fd.get('wipe_comments') ? '1' : '0',
            anonymize_contacts: fd.get('anonymize_contacts') ? '1' : '0',
            dry_run: fd.get('dry_run') ? '1' : '0',
            backup_confirmed: fd.get('backup_confirmed') ? '1' : '0',
            confirmation_phrase: fd.get('confirmation_phrase') || '',
            include_keys: []
        };

        const selectedKeys = form.querySelectorAll('input[name="include_keys[]"]:checked');
        selectedKeys.forEach((node) => {
            out.include_keys.push(node.value);
        });

        return out;
    }

    function validateRealRun(state) {
        if (state.dry_run === '1') {
            return;
        }
        if (!canRunReal) {
            throw new Error('Production-like environment is blocked until allow_prod is enabled in config.local.php.');
        }
        if (state.backup_confirmed !== '1' || state.confirmation_phrase !== 'ANONYMIZE') {
            throw new Error('Disable dry-run only after checking the backup box and typing ANONYMIZE exactly.');
        }
    }

    async function postAction(payload) {
        const body = new URLSearchParams();
        Object.keys(payload).forEach((key) => {
            if (Array.isArray(payload[key])) {
                payload[key].forEach((value) => {
                    body.append('include_keys[]', String(value));
                });
            } else {
                body.append(key, String(payload[key]));
            }
        });

        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString()
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || ('HTTP ' + response.status));
        }
        return data.data;
    }

    function renderKeys(keys) {
        keysList.innerHTML = '';

        if (!keys.length) {
            keysList.innerHTML = '<div class="kitem">No candidate keys were detected.</div>';
            return;
        }

        keys.forEach((key) => {
            const item = document.createElement('label');
            item.className = 'kitem';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'include_keys[]';
            checkbox.value = key;
            checkbox.checked = true;

            item.appendChild(checkbox);
            item.appendChild(document.createTextNode(' ' + key));
            keysList.appendChild(item);
        });
    }

    function updateProgress(progress, total, done) {
        const percent = total > 0 ? Math.min(100, Math.round((progress / total) * 100)) : (done ? 100 : 0);
        progressBar.style.width = percent + '%';
        progressText.textContent = done
            ? 'Completed: ' + progress + ' / ' + total
            : 'Running: ' + progress + ' / ' + total + ' (' + percent + '%)';
        progressText.className = 'status ' + (done ? 'ok' : '');
    }

    previewBtn.addEventListener('click', async function () {
        try {
            setBusy(true);
            appendLog('Preview started.');
            const state = collectFormState();
            const result = await postAction({
                action: 'preview',
                csrf_token: state.csrf_token,
                days: state.days
            });

            previewBlock.style.display = 'block';
            previewInfo.textContent = 'Orders before cutoff (' + result.cutoff + '): ' + result.total_orders;
            previewInfo.className = 'status ok';
            renderKeys(result.candidate_keys || []);
            appendLog('Preview done. Old orders: ' + result.total_orders + '.');
        } catch (error) {
            previewInfo.textContent = String(error.message || error);
            previewInfo.className = 'status err';
            appendLog('Preview failed: ' + String(error.message || error));
        } finally {
            setBusy(false);
        }
    });

    stopBtn.addEventListener('click', function () {
        stopRequested = true;
        appendLog('Stop requested by user. Current batch will finish first.');
    });

    runBtn.addEventListener('click', async function () {
        stopRequested = false;
        progressBar.style.width = '0%';
        progressText.textContent = 'Starting...';
        progressText.className = 'status';

        let cursor = 0;

        try {
            validateRealRun(collectFormState());
            setBusy(true);
            while (true) {
                const state = collectFormState();
                validateRealRun(state);

                const payload = {
                    action: 'run',
                    csrf_token: state.csrf_token,
                    days: state.days,
                    limit: state.limit,
                    cursor: String(cursor),
                    keep_geo: state.keep_geo,
                    wipe_comments: state.wipe_comments,
                    anonymize_contacts: state.anonymize_contacts,
                    dry_run: state.dry_run,
                    backup_confirmed: state.backup_confirmed,
                    confirmation_phrase: state.confirmation_phrase,
                    include_keys: state.include_keys
                };

                const data = await postAction(payload);

                cursor = Number(data.cursor || cursor);
                updateProgress(Number(data.progress || 0), Number(data.total || 0), Boolean(data.done));

                appendLog(
                    'Batch: orders processed=' + (data.processed_orders || []).length +
                    ', skipped=' + Object.keys(data.skipped_orders || {}).length +
                    ', contacts processed=' + (data.processed_contacts || []).length +
                    ', contacts skipped=' + Object.keys(data.skipped_contacts || {}).length +
                    ', cursor=' + (data.cursor || 0) +
                    ', dry_run=' + (data.dry_run ? 'yes' : 'no') +
                    ', run_id=' + (data.run_id || 'n/a') +
                    ', log=' + (data.batch_log || 'n/a')
                );

                if (stopRequested) {
                    progressText.textContent = 'Stopped by user at cursor=' + cursor;
                    progressText.className = 'status err';
                    break;
                }

                if (data.done) {
                    appendLog('Run completed at cursor=' + cursor + '.');
                    break;
                }
            }
        } catch (error) {
            progressText.textContent = 'Failed: ' + String(error.message || error);
            progressText.className = 'status err';
            appendLog('Run failed: ' + String(error.message || error));
        } finally {
            setBusy(false);
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
