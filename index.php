<?php
declare(strict_types=1);

function depersonalizerSessionCookiePath(): string
{
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($scriptName));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '/';
    }

    return rtrim($dir, '/') . '/';
}

session_name('depersonalizer_sid');
session_set_cookie_params(array(
    'lifetime' => 0,
    'path' => depersonalizerSessionCookiePath(),
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
));
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
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Доступ к обезличиванию Webasyst</title>
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
    <h1>Требуется доступ</h1>
    <?php if (!$isConfigured): ?>
        <p>Инструмент закрыт до настройки токена доступа.</p>
        <p>Задайте <code>DEPERSONALIZER_ACCESS_TOKEN</code> в окружении сервера или добавьте <code>access_token</code> в <code>config.local.php</code>.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <label for="access_token">Токен доступа</label>
            <input id="access_token" name="access_token" type="password" autocomplete="current-password" autofocus>
            <button type="submit">Открыть</button>
        </form>
        <p>Если вход не срабатывает, очистите cookies для shop.incyber.ru или откройте страницу в режиме инкогнито.</p>
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

    renderAccessPage(true, 'Неверный токен доступа.');
}

if ($requestMethod === 'POST' && $requestAction === 'logout') {
    unset($_SESSION['depersonalizer_authenticated'], $_SESSION['depersonalizer_csrf']);
    session_regenerate_id(true);
    depersonalizerRedirectBack();
}

if (!$accessConfigured) {
    if ($requestMethod === 'POST') {
        jsonResponse(array('ok' => false, 'error' => 'Токен доступа не настроен.'), 503);
    }
    renderAccessPage(false);
}

if (!depersonalizerIsAuthorized($dbConfig)) {
    if ($requestMethod === 'POST') {
        jsonResponse(array('ok' => false, 'error' => 'Доступ не разрешен.'), 401);
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
    $source = (string)($dbConfig['__source'] ?? '');
    $configError = 'Загружен источник конфигурации: ' . ($source !== '' ? $source : 'нет') .
        '. Не хватает обязательных ключей: ' . implode(', ', $missingConfigKeys) . '.';
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
            $schemaError = 'Отсутствуют обязательные таблицы: ' . implode(', ', $preflight['missing_required_tables']);
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
        jsonResponse(array('ok' => false, 'error' => 'Неподдерживаемое действие.'), 400);
    }

    $incomingToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $incomingToken)) {
        jsonResponse(array('ok' => false, 'error' => 'CSRF token не совпадает.'), 403);
    }

    if (!$pageReady) {
        $kind = 'Ошибка проверки схемы';
        $message = (string)$schemaError;
        if ($configError !== null) {
            $kind = 'Ошибка конфигурации';
            $message = $configError;
        } elseif ($dbConnectionError !== null) {
            $kind = 'Ошибка подключения к БД';
            $message = $dbConnectionError;
        }
        jsonResponse(array('ok' => false, 'error' => $kind . ': ' . $message), 500);
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
            'history_cursor' => readPostInt('history_cursor', 0),
            'keep_geo' => readPostBool('keep_geo'),
            'wipe_comments' => readPostBool('wipe_comments'),
            'anonymize_contacts' => readPostBool('anonymize_contacts'),
            'contact_catchup_only' => readPostBool('contact_catchup_only'),
            'anonymize_order_history' => readPostBool('anonymize_order_history'),
            'dry_run' => readPostBool('dry_run'),
            'backup_confirmed' => readPostBool('backup_confirmed'),
            'confirmation_phrase' => (string)($_POST['confirmation_phrase'] ?? ''),
            'run_id' => (string)($_POST['run_id'] ?? ''),
            'include_keys' => isset($_POST['include_keys']) && is_array($_POST['include_keys'])
                ? array_values($_POST['include_keys'])
                : array(),
        );

        if (empty($options['dry_run'])) {
            if (empty($environment['can_run_real'])) {
                jsonResponse(array(
                    'ok' => false,
                    'error' => 'Обнаружена среда, похожая на продакшен. Установите allow_prod => true в config.local.php только после проверки целевой БД.',
                ), 403);
            }
            if (empty($options['backup_confirmed']) || trim((string)$options['confirmation_phrase']) !== 'ANONYMIZE') {
                jsonResponse(array(
                    'ok' => false,
                    'error' => 'Для реального обезличивания нужна отметка о свежей резервной копии и точное подтверждение ANONYMIZE.',
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
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Обезличивание Webasyst</title>
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
            white-space: pre-line;
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

        .tooltip-popover {
            position: fixed;
            z-index: 1000;
            display: none;
            max-width: 360px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            font-size: 13px;
            line-height: 1.45;
            box-shadow: 0 10px 24px rgba(17, 24, 39, 0.22);
        }

        [data-tooltip] {
            cursor: help;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Обезличивание Webasyst</h1>
            <p class="subtitle">Отдельная страница. Установка плагина не требуется.</p>
        </div>
        <div class="card" style="min-width: 320px; margin: 0;">
            <div style="margin-bottom: 8px;">
                <span class="badge <?php echo !empty($environment['is_prod']) ? 'badge-prod' : 'badge-test'; ?>">
                    <?php echo htmlspecialchars((string)$environment['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if (!empty($environment['is_prod']) && empty($environment['allow_prod'])): ?>
                    <span style="margin-left: 8px; color: var(--danger); font-weight: 600;">реальный запуск заблокирован</span>
                <?php endif; ?>
            </div>
            <div><strong>Источник подключения:</strong> <code><?php echo htmlspecialchars((string)($dbConfig['__source'] ?? 'config.local.php / вручную'), ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>БД:</strong> <code><?php echo htmlspecialchars((string)$dbConfig['database'], ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>MySQL:</strong> <code><?php echo htmlspecialchars($mysqlVersion, ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>PHP:</strong> <code><?php echo htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>Схема:</strong> <code><?php echo $pageReady ? 'совместима' : 'не готова'; ?></code></div>
            <form method="post" style="margin-top: 10px;">
                <input type="hidden" name="action" value="logout">
                <button class="btn-ghost" type="submit">Закрыть доступ</button>
            </form>
        </div>
    </div>

    <?php if ($configError !== null): ?>
        <div class="card error">
            <strong>Ошибка конфигурации:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($configError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Используйте плоский формат <code>config.local.php</code> из <code>config.local.php.example</code>. Пароль здесь никогда не выводится.</div>
        </div>
    <?php endif; ?>

    <?php if ($dbConnectionError !== null): ?>
        <div class="card error">
            <strong>Ошибка подключения к БД:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($dbConnectionError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Конфигурация загружена, но PDO не смог подключиться к MySQL.</div>
        </div>
    <?php endif; ?>

    <?php if ($schemaError !== null): ?>
        <div class="card error">
            <strong>Ошибка проверки схемы:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($schemaError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Основной сценарий по заказам требует только <code>shop_order</code> и <code>shop_order_params</code>. Необязательные таблицы контактов не нужны для загрузки страницы.</div>
        </div>
    <?php endif; ?>

    <?php if ($pageReady): ?>
        <div class="card warning">
            <strong>Безопасный режим, чтобы не конфликтовать с ядром Webasyst</strong>
            <ul>
                <?php foreach ($safeModeNotes as $line): ?>
                    <li><?php echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top: 8px;">
                Совместимость схемы:
                <code>wa_contact=<?php echo !empty($optionalTables['wa_contact']) ? 'да' : 'нет'; ?></code>,
                <code>wa_contact_emails=<?php echo !empty($optionalTables['wa_contact_emails']) ? 'да' : 'нет'; ?></code>,
                <code>wa_contact_data=<?php echo !empty($optionalTables['wa_contact_data']) ? 'да' : 'нет'; ?></code>,
                <code>wa_contact_data_text=<?php echo !empty($optionalTables['wa_contact_data_text']) ? 'да' : 'нет'; ?></code>,
                <code>wa_contact_addresses=<?php echo !empty($optionalTables['wa_contact_addresses']) ? 'да' : 'нет'; ?></code>,
                <code>wa_contact_params=<?php echo !empty($optionalTables['wa_contact_params']) ? 'да' : 'нет'; ?></code>,
                <code>state=<?php echo !empty($optionalTables['state_table_ready']) ? 'готова' : 'не готова'; ?></code>
            </div>
            <?php if (empty($optionalTables['wa_contact_params'])): ?>
                <div style="margin-top: 8px;">wa_contact_params отсутствует. Обработка контактов будет использовать отдельную таблицу состояния <code>depersonalizer_state</code>.</div>
            <?php endif; ?>
            <?php if (empty($optionalTables['state_table_ready']) && !empty($optionalTables['state_table_error'])): ?>
                <div style="margin-top: 8px;">Проблема с таблицей состояния: <?php echo htmlspecialchars((string)$optionalTables['state_table_error'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($environment['is_prod']) && empty($environment['allow_prod'])): ?>
            <div class="card error">
                <strong>Обнаружена среда, похожая на продакшен.</strong>
                <div style="margin-top: 6px;">Предпросмотр и пробный запуск доступны, но реальное обезличивание заблокировано, пока <code>'allow_prod' =&gt; true</code> не будет намеренно задано в локальной конфигурации.</div>
            </div>
        <?php endif; ?>

        <div class="card">
            <form id="mainForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="grid">
                    <div>
                        <label for="days">Срок хранения, дней</label>
                        <input id="days" name="days" type="number" value="365" min="1" max="36500">
                    </div>
                    <div>
                        <label for="limit">Размер пакета</label>
                        <input id="limit" name="limit" type="number" value="200" min="1" max="1000">
                    </div>
                    <div>
                        <label>Метка заказа / состояние контакта</label>
                        <input type="text" value="_depersonalizer_ext_processed / depersonalizer_state" readonly>
                    </div>
                </div>

                <div class="checkbox-row">
                    <label tabindex="0" data-tooltip="Перед очисткой адресных полей сохраняет грубую географию в geo_city, geo_region, geo_country, geo_lat и geo_lng. Существующие непустые geo_* не перезаписываются."><input type="checkbox" name="keep_geo" value="1" checked> Сохранить гео-снимок в <code>geo_*</code></label>
                    <label tabindex="0" data-tooltip="Очищает выбранные поля комментариев. Включайте только если в комментариях могут быть персональные данные."><input type="checkbox" name="wipe_comments" value="1"> Стереть комментарии заказов</label>
                    <label tabindex="0" data-tooltip="Дополнительно обезличивает карточки контактов, у которых нет заказов новее выбранного срока хранения. Более рискованный режим, чем обработка только заказов."><input type="checkbox" name="anonymize_contacts" value="1" <?php echo empty($optionalTables['wa_contact']) || empty($optionalTables['state_table_ready']) ? 'disabled' : ''; ?>> Обезличить контакты без новых заказов</label>
                    <label tabindex="0" data-tooltip="Обрабатывает контакты по старым заказам даже если сами заказы уже были обезличены и помечены как обработанные. Заказы при этом не меняются."><input type="checkbox" name="contact_catchup_only" value="1" <?php echo empty($optionalTables['wa_contact']) ? 'disabled' : ''; ?>> Только контакты по уже обработанным старым заказам</label>
                    <label tabindex="0" data-tooltip="Очищает текстовые записи истории заказа, где могут оставаться имена, ФИО, номера отправлений, комментарии и другие персональные данные. Даты, статусы и структура заказа не удаляются."><input type="checkbox" name="anonymize_order_history" value="1"> Обезличить историю выполнения заказов</label>
                    <label tabindex="0" data-tooltip="Показывает, что было бы обработано, но не записывает изменения в базу. Рекомендуется всегда сначала запускать этот режим."><input type="checkbox" name="dry_run" value="1" checked> Пробный запуск без записи</label>
                </div>

                <div class="protection">
                    <div class="checkbox-row">
                        <label tabindex="0" data-tooltip="Подтверждение, что перед реальным запуском сделана свежая резервная копия базы. Без этого реальные изменения заблокированы."><input type="checkbox" name="backup_confirmed" value="1"> У меня есть свежая резервная копия базы</label>
                    </div>
                    <div style="max-width: 320px; margin-top: 10px;">
                        <label for="confirmation_phrase">Для реального запуска введите ANONYMIZE</label>
                        <input id="confirmation_phrase" name="confirmation_phrase" type="text" value="" autocomplete="off">
                    </div>
                </div>

                <div class="actions">
                    <button class="btn-ghost" type="button" id="previewBtn">Предпросмотр</button>
                    <button class="btn-primary" type="button" id="runBtn">Запустить</button>
                    <button class="btn-ghost" type="button" id="stopBtn" disabled>Остановить</button>
                </div>

                <div id="previewBlock" style="display:none; margin-top: 16px;">
                    <strong>Найденные поля</strong>
                    <div id="previewInfo" class="status"></div>
                    <div id="keysList" class="klist"></div>
                </div>
            </form>
        </div>

        <div class="card">
            <strong>Ход выполнения</strong>
            <div class="progress-wrap">
                <div id="progressBar" class="progress-bar"></div>
            </div>
            <div id="progressText" class="status">Ожидание</div>
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

    function generateRunId() {
        const bytes = new Uint8Array(8);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i += 1) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }

        const suffix = Array.from(bytes).map((value) => value.toString(16).padStart(2, '0')).join('');
        return 'run_' + Date.now().toString(36) + '_' + suffix;
    }

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
            contact_catchup_only: fd.get('contact_catchup_only') ? '1' : '0',
            anonymize_order_history: fd.get('anonymize_order_history') ? '1' : '0',
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
            throw new Error('Обнаружена среда, похожая на продакшен. Реальный запуск заблокирован, пока allow_prod не включен в config.local.php.');
        }
        if (state.backup_confirmed !== '1' || state.confirmation_phrase !== 'ANONYMIZE') {
            throw new Error('Отключайте пробный запуск только после отметки о резервной копии и точного ввода ANONYMIZE.');
        }
    }

    function setupTooltips() {
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip-popover';
        document.body.appendChild(tooltip);

        let timer = null;
        let activeNode = null;

        function hideTooltip() {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
            }
            tooltip.style.display = 'none';
            activeNode = null;
        }

        function showTooltip(node) {
            const text = node.getAttribute('data-tooltip') || '';
            if (text === '') {
                return;
            }

            tooltip.textContent = text;
            tooltip.style.display = 'block';

            const rect = node.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const left = Math.max(12, Math.min(rect.left, window.innerWidth - tooltipRect.width - 12));
            let top = rect.bottom + 8;
            if (top + tooltipRect.height > window.innerHeight - 12) {
                top = Math.max(12, rect.top - tooltipRect.height - 8);
            }

            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        function scheduleTooltip(node) {
            hideTooltip();
            activeNode = node;
            timer = window.setTimeout(() => {
                if (activeNode === node) {
                    showTooltip(node);
                }
            }, 2000);
        }

        document.querySelectorAll('[data-tooltip]').forEach((node) => {
            node.addEventListener('mouseenter', () => scheduleTooltip(node));
            node.addEventListener('mouseleave', hideTooltip);
            node.addEventListener('focusin', () => scheduleTooltip(node));
            node.addEventListener('focusout', hideTooltip);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                hideTooltip();
            }
        });
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
            keysList.innerHTML = '<div class="kitem">Подходящие поля не найдены.</div>';
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
            ? 'Завершено: ' + progress + ' / ' + total
            : 'Выполняется: ' + progress + ' / ' + total + ' (' + percent + '%)';
        progressText.className = 'status ' + (done ? 'ok' : '');
    }

    previewBtn.addEventListener('click', async function () {
        try {
            setBusy(true);
            appendLog('Предпросмотр запущен.');
            const state = collectFormState();
            const result = await postAction({
                action: 'preview',
                csrf_token: state.csrf_token,
                days: state.days
            });

            previewBlock.style.display = 'block';
            const contactCatchup = result.contact_catchup || {};
            const orderHistory = result.order_history || {};
            const normalization = result.placeholder_normalization || {};
            const previewLines = [
                'Старых заказов всего (' + result.cutoff + '): ' + (result.old_orders_total || result.total_orders || 0),
                'Заказов к обработке сейчас: ' + result.total_orders
            ];

            if (orderHistory.available) {
                previewLines.push('Записей истории заказов к обезличиванию: ' + (orderHistory.rows_to_process || 0));
                previewLines.push('Заказов с уже обработанной историей: ' + (orderHistory.already_marked_orders || 0));
                previewLines.push('Колонки истории заказов: ' + (orderHistory.columns || []).join(', '));
            } else if (orderHistory.note) {
                previewLines.push(orderHistory.note);
            }

            previewLines.push('Старых плейсхолдеров к нормализации: ' + (normalization.total || 0));

            if (contactCatchup.available) {
                previewLines.push('Контактов для догоняющей обработки: ' + (contactCatchup.eligible_contacts || 0));
                previewLines.push('Контактов уже помечено обработанными: ' + (contactCatchup.already_processed_contacts || 0));
                previewLines.push('Контактов с более новыми заказами: ' + (contactCatchup.contacts_with_newer_orders || 0));
                previewLines.push('Защищенных контактов будет пропущено: ' + (contactCatchup.protected_contacts || 0));
            } else if (contactCatchup.note) {
                previewLines.push(contactCatchup.note);
            }

            previewInfo.textContent = previewLines.join('\n');
            previewInfo.className = 'status ok';
            renderKeys(result.candidate_keys || []);
            appendLog('Предпросмотр завершен. Заказов к обработке: ' + result.total_orders + ', строк истории: ' + (orderHistory.rows_to_process || 0) + ', старых плейсхолдеров: ' + (normalization.total || 0) + ', контактов для догоняющей обработки: ' + (contactCatchup.eligible_contacts || 0) + '.');
        } catch (error) {
            previewInfo.textContent = String(error.message || error);
            previewInfo.className = 'status err';
            appendLog('Ошибка предпросмотра: ' + String(error.message || error));
        } finally {
            setBusy(false);
        }
    });

    stopBtn.addEventListener('click', function () {
        stopRequested = true;
        appendLog('Запрошена остановка. Текущий пакет сначала завершится.');
    });

    runBtn.addEventListener('click', async function () {
        stopRequested = false;
        progressBar.style.width = '0%';
        progressText.textContent = 'Запуск...';
        progressText.className = 'status';

        let cursor = 0;
        let historyCursor = 0;
        const runId = generateRunId();

        try {
            const initialState = collectFormState();
            validateRealRun(initialState);
            const runIsDryRun = initialState.dry_run === '1';
            const storedConfirmationPhrase = initialState.confirmation_phrase;
            if (runIsDryRun && initialState.anonymize_order_history === '1') {
                appendLog('Обработка истории заказов запущена в dry-run: записи не будут изменены.');
            }
            if (!runIsDryRun) {
                const confirmationInput = form.querySelector('input[name="confirmation_phrase"]');
                if (confirmationInput) {
                    confirmationInput.value = '';
                }
                appendLog('Поле подтверждения ANONYMIZE очищено для защиты от повторного реального запуска.');
            }

            setBusy(true);
            appendLog('Запуск начат. run_id=' + runId + '.');
            while (true) {
                const state = collectFormState();
                state.dry_run = initialState.dry_run;
                state.backup_confirmed = initialState.backup_confirmed;
                state.confirmation_phrase = runIsDryRun ? state.confirmation_phrase : storedConfirmationPhrase;
                state.contact_catchup_only = initialState.contact_catchup_only;
                state.anonymize_order_history = initialState.anonymize_order_history;
                validateRealRun(state);

                const payload = {
                    action: 'run',
                    csrf_token: state.csrf_token,
                    days: state.days,
                    limit: state.limit,
                    cursor: String(cursor),
                    history_cursor: String(historyCursor),
                    keep_geo: state.keep_geo,
                    wipe_comments: state.wipe_comments,
                    anonymize_contacts: state.anonymize_contacts,
                    contact_catchup_only: state.contact_catchup_only,
                    anonymize_order_history: state.anonymize_order_history,
                    dry_run: state.dry_run,
                    backup_confirmed: state.backup_confirmed,
                    confirmation_phrase: state.confirmation_phrase,
                    run_id: runId,
                    include_keys: state.include_keys
                };

                const data = await postAction(payload);

                cursor = Number(data.cursor || cursor);
                historyCursor = Number(data.history_cursor || historyCursor);
                updateProgress(Number(data.progress || 0), Number(data.total || 0), Boolean(data.done));

                appendLog(
                    'Пакет: обработано заказов=' + (data.processed_orders || []).length +
                    ', пропущено заказов=' + Object.keys(data.skipped_orders || {}).length +
                    ', обработано контактов=' + (data.processed_contacts || []).length +
                    ', пропущено контактов=' + Object.keys(data.skipped_contacts || {}).length +
                    ', строк истории обработано=' + (data.processed_history_rows || 0) +
                    ', старых плейсхолдеров нормализовано=' + (data.normalized_placeholders || 0) +
                    ', cursor=' + (data.cursor || 0) +
                    ', history_cursor=' + (data.history_cursor || 0) +
                    ', dry_run=' + (data.dry_run ? 'да' : 'нет') +
                    ', run_id=' + (data.run_id || 'n/a') +
                    ', log=' + (data.batch_log || 'n/a')
                );

                if (stopRequested) {
                    progressText.textContent = 'Остановлено пользователем на cursor=' + cursor;
                    progressText.className = 'status err';
                    break;
                }

                if (data.done) {
                    appendLog('Запуск завершен на cursor=' + cursor + '.');
                    break;
                }
            }
        } catch (error) {
            progressText.textContent = 'Ошибка: ' + String(error.message || error);
            progressText.className = 'status err';
            appendLog('Ошибка запуска: ' + String(error.message || error));
        } finally {
            setBusy(false);
        }
    });

    setupTooltips();
})();
</script>
<?php endif; ?>
</body>
</html>
