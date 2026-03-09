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

if (empty($_SESSION['depersonalizer_csrf'])) {
    $_SESSION['depersonalizer_csrf'] = bin2hex(random_bytes(24));
}

$csrfToken = (string)$_SESSION['depersonalizer_csrf'];
$dbConfig = StandaloneConfigLoader::load(__DIR__);

$service = null;
$preflight = null;
$connectionError = null;

try {
    if ((string)$dbConfig['database'] === '') {
        throw new RuntimeException('Database name is empty. Configure config.local.php or place script in/under Webasyst root.');
    }

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
    $preflight = $service->preflight();

    if (!empty($preflight['missing_required_tables'])) {
        throw new RuntimeException(
            'Missing required tables: ' . implode(', ', $preflight['missing_required_tables'])
        );
    }
} catch (Throwable $error) {
    $connectionError = $error->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!in_array($action, array('preview', 'run'), true)) {
        jsonResponse(array('ok' => false, 'error' => 'Unsupported action.'), 400);
    }

    $incomingToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $incomingToken)) {
        jsonResponse(array('ok' => false, 'error' => 'CSRF token mismatch.'), 403);
    }

    if (!$service instanceof StandaloneDepersonalizer) {
        jsonResponse(array('ok' => false, 'error' => 'Database connection failed: ' . $connectionError), 500);
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
            'include_keys' => isset($_POST['include_keys']) && is_array($_POST['include_keys'])
                ? array_values($_POST['include_keys'])
                : array(),
        );

        $result = $service->runBatch($options);
        jsonResponse(array('ok' => true, 'data' => $result));
    } catch (Throwable $error) {
        jsonResponse(array('ok' => false, 'error' => $error->getMessage()), 500);
    }
}

$optionalTables = array(
    'wa_contact_emails' => false,
    'wa_contact_data' => false,
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
            <div><strong>Connection source:</strong> <code><?php echo htmlspecialchars((string)($dbConfig['__source'] ?? 'config.local.php / manual'), ENT_QUOTES, 'UTF-8'); ?></code></div>
            <div style="margin-top: 6px;"><strong>DB:</strong> <code><?php echo htmlspecialchars((string)$dbConfig['database'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        </div>
    </div>

    <?php if ($connectionError !== null): ?>
        <div class="card error">
            <strong>Database connection error:</strong>
            <div style="margin-top: 6px;"><?php echo htmlspecialchars($connectionError, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top: 8px;">Create <code>config.local.php</code> with DB credentials and reload the page.</div>
        </div>
    <?php endif; ?>

    <?php if ($connectionError === null): ?>
        <div class="card warning">
            <strong>Safe-mode behavior (to avoid Webasyst core conflicts)</strong>
            <ul>
                <?php foreach ($safeModeNotes as $line): ?>
                    <li><?php echo htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
            <div style="margin-top: 8px;">
                Optional contact tables found:
                <code>wa_contact_emails=<?php echo !empty($optionalTables['wa_contact_emails']) ? 'yes' : 'no'; ?></code>,
                <code>wa_contact_data=<?php echo !empty($optionalTables['wa_contact_data']) ? 'yes' : 'no'; ?></code>
            </div>
        </div>

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
                        <label>Processed mark key (orders / contacts)</label>
                        <input type="text" value="_depersonalizer_ext_processed" readonly>
                    </div>
                </div>

                <div class="checkbox-row">
                    <label><input type="checkbox" name="keep_geo" value="1" checked> Keep geo snapshot in <code>geo_*</code></label>
                    <label><input type="checkbox" name="wipe_comments" value="1"> Wipe order comments</label>
                    <label><input type="checkbox" name="anonymize_contacts" value="1"> Anonymize contacts without newer orders</label>
                    <label><input type="checkbox" name="dry_run" value="1" checked> Dry-run (no writes)</label>
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

<?php if ($connectionError === null): ?>
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
            include_keys: []
        };

        const selectedKeys = form.querySelectorAll('input[name="include_keys[]"]:checked');
        selectedKeys.forEach((node) => {
            out.include_keys.push(node.value);
        });

        return out;
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
            item.innerHTML = '<input type="checkbox" name="include_keys[]" value="' +
                key.replace(/"/g, '&quot;') +
                '" checked> ' + key;
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
        setBusy(true);
        progressBar.style.width = '0%';
        progressText.textContent = 'Starting...';
        progressText.className = 'status';

        let cursor = 0;

        try {
            while (true) {
                const state = collectFormState();

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
                    include_keys: state.include_keys
                };

                const data = await postAction(payload);

                cursor = Number(data.cursor || cursor);
                updateProgress(Number(data.progress || 0), Number(data.total || 0), Boolean(data.done));

                appendLog(
                    'Batch: orders processed=' + (data.processed_orders || []).length +
                    ', skipped=' + Object.keys(data.skipped_orders || {}).length +
                    ', contacts processed=' + (data.processed_contacts || []).length +
                    ', dry_run=' + (data.dry_run ? 'yes' : 'no') +
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
