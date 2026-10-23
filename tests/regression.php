<?php
declare(strict_types=1);

require_once __DIR__ . '/Fixture.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$passed = 0;
$skipped = 0;
function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}
function rejects(callable $call, string $fragment): void
{
    try {
        $call();
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $fragment)) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected rejection: ' . $fragment);
}
function check(string $name, callable $test): void
{
    global $passed, $skipped;
    if ($test() === false) {
        $skipped++;
        echo 'SKIP ' . $name . PHP_EOL;
        return;
    }
    $passed++;
    echo 'PASS ' . $name . PHP_EOL;
}
function seedContact(PDO $db, int $id, int $staff = 0, string $name = 'PRIVATE-FIXTURE'): void
{
    $db->prepare('INSERT INTO wa_contact (id,name,is_staff) VALUES (?,?,?)')->execute(array($id, $name, $staff));
}
function markContact(PDO $db, int $id): void
{
    $db->prepare("INSERT INTO depersonalizer_state VALUES ('contact',?,'2001-01-01 00:00:00','old_fixture','processed',NULL)")
        ->execute(array($id));
}
function ids(array $batches, string $field): array
{
    return array_merge(...array_column($batches, $field));
}

check('F01: normalization is scoped, provenanced and protected', function (): void {
    [$db, $service] = fixture();
    foreach (array(1, 2, 3, 4) as $id) {
        seedContact($db, $id, $id === 2 ? 1 : 0, 'Deleted');
        seedOrder($db, $id, $id);
        seedParam($db, '_depersonalizer_ext_processed', '1', $id);
        if ($id !== 4) {
            markContact($db, $id);
        }
    }
    seedOrder($db, 5, 3, '2099-01-01 00:00:00');
    seedParam($db, 'email', 'anon+order_1@example.invalid');
    seedParam($db, 'payment_name', 'Deleted');
    seedParam($db, 'sdekint_plugin.to.contact.name', 'Deleted');
    $db->exec("INSERT INTO wa_contact_emails VALUES (1,1,'anon+contact_1_1@example.invalid','unavailable')");
    runToEnd($service, realOptions());
    same(array('Deleted', 'Deleted', 'Deleted', 'Deleted'), $db->query('SELECT name FROM wa_contact ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    same('Deleted', value($db, "SELECT value FROM shop_order_params WHERE name='payment_name'"));
    same('Deleted', value($db, "SELECT value FROM shop_order_params WHERE name='sdekint_plugin.to.contact.name'"));
    $beforeOrders = $db->query('SELECT * FROM shop_order_params ORDER BY order_id,name')->fetchAll(PDO::FETCH_ASSOC);
    $batches = runToEnd($service, realOptions(array('anonymize_orders' => false, 'anonymize_contacts' => true, 'contact_order_scope' => 'processed')));
    same(array(1, 4), ids($batches, 'processed_contacts'));
    same('Обезличен', value($db, 'SELECT name FROM wa_contact WHERE id=1'));
    same('Deleted', value($db, 'SELECT name FROM wa_contact WHERE id=2'));
    same('Deleted', value($db, 'SELECT name FROM wa_contact WHERE id=3'));
    same('obezlicheno+contact_1_1@obezlicheno.invalid', value($db, 'SELECT email FROM wa_contact_emails'));
    same($beforeOrders, $db->query('SELECT * FROM shop_order_params ORDER BY order_id,name')->fetchAll(PDO::FETCH_ASSOC));
    same(array(), ids(runToEnd($service, realOptions(array('anonymize_orders' => false, 'anonymize_contacts' => true))), 'processed_contacts'));
});

check('F02/F07/F08/F09: empty selection, core comments, geo and incremental fields', function (): void {
    [$db, $service] = fixture();
    seedOrder($db);
    foreach (array('email' => 'PRIVATE-EMAIL', 'phone' => 'PRIVATE-PHONE', 'comment' => 'PRIVATE-COMMENT',
        'shipping_address.city' => 'PRIVATE-CITY', 'geo_city' => 'KEPT-CITY', 'payment_name' => 'Deleted',
        'shipping_name' => 'Deleted', 'cdek.name' => 'PRIVATE-CARRIER') as $key => $value) {
        seedParam($db, $key, $value);
    }
    $before = snapshot($db);
    same(array(), ids(runToEnd($service, realOptions(array('include_keys' => array()))), 'processed_orders'));
    same($before, snapshot($db));
    runToEnd($service, realOptions());
    same('PRIVATE-PHONE', value($db, "SELECT value FROM shop_order_params WHERE name='phone'"));
    runToEnd($service, realOptions(array('include_keys' => array('phone', 'shipping_address.city', 'geo_city', 'payment_name', 'shipping_name', 'cdek.name'), 'keep_geo' => true)));
    same('obezlicheno-order-' . sha1('1'), value($db, "SELECT value FROM shop_order_params WHERE name='phone'"));
    same('KEPT-CITY', value($db, "SELECT value FROM shop_order_params WHERE name='geo_city'"));
    same('Deleted', value($db, "SELECT value FROM shop_order_params WHERE name='shipping_name'"));
    same('PRIVATE-CARRIER', value($db, "SELECT value FROM shop_order_params WHERE name='cdek.name'"));
    runToEnd($service, realOptions(array('include_keys' => array(), 'wipe_comments' => true)));
    same('', value($db, 'SELECT comment FROM shop_order'));
    same('', value($db, "SELECT value FROM shop_order_params WHERE name='comment'"));
    same($before['shop_order'][0]['total'], value($db, 'SELECT total FROM shop_order'));
    same('paid', value($db, 'SELECT state_id FROM shop_order'));
    same(array(), ids(runToEnd($service, realOptions(array('include_keys' => array('email', 'phone'), 'wipe_comments' => true))), 'processed_orders'));
    $preview = $service->preview(365);
    same(2, $preview['damaged_service_fields']);
    same(false, in_array('geo_city', $preview['candidate_keys'], true));
    same(false, in_array('payment_name', $preview['candidate_keys'], true));
    same(false, in_array('cdek.name', $preview['candidate_keys'], true));
});

check('F04/F06: independent scopes and contacts-first pagination', function (): void {
    foreach (array(true, false) as $dry) {
        [$db, $service] = fixture();
        seedContact($db, 100);
        seedContact($db, 1);
        seedOrder($db, 1, 100);
        seedOrder($db, 2, 1);
        seedParam($db, 'email', 'PRIVATE-1', 1);
        seedParam($db, 'email', 'PRIVATE-2', 2);
        $before = snapshot($db);
        $batches = runToEnd($service, realOptions(array('dry_run' => $dry, 'anonymize_contacts' => true, 'contact_order_scope' => 'unprocessed')));
        same(array(1, 100), ids($batches, 'processed_contacts'));
        same(array(1, 2), ids($batches, 'processed_orders'));
        if ($dry) {
            same($before, snapshot($db));
            same(0, (int)value($db, 'SELECT COUNT(*) FROM depersonalizer_state'));
        }
    }
    foreach (array('processed' => array(1), 'unprocessed' => array(2), 'all' => array(1, 2)) as $scope => $expected) {
        [$db, $service] = fixture();
        seedContact($db, 1);
        seedContact($db, 2);
        seedOrder($db, 1, 1);
        seedOrder($db, 2, 2);
        seedParam($db, '_depersonalizer_ext_processed', '1');
        $orders = snapshot($db);
        $batches = runToEnd($service, realOptions(array('anonymize_orders' => false, 'anonymize_contacts' => true, 'contact_order_scope' => $scope)));
        same($expected, ids($batches, 'processed_contacts'));
        same($orders['shop_order'], snapshot($db)['shop_order']);
        same($orders['shop_order_params'], snapshot($db)['shop_order_params']);
    }
});

check('F10/F11/F17: history uses row cursor, preserves actors, handles appended rows', function (): void {
    [$db, $service] = fixture();
    seedOrder($db);
    $insert = $db->prepare("INSERT INTO shop_order_log VALUES (?,1,99,'pay','2000-01-02','PRIVATE-HISTORY','STAFF-CACHE','STAFF-ACTOR')");
    $insert->execute(array(1));
    $insert->execute(array(2));
    $options = realOptions(array('anonymize_orders' => false, 'anonymize_order_history' => true));
    $before = snapshot($db);
    $dry = runToEnd($service, array_replace($options, array('dry_run' => true)));
    same(array(1, 1), array_column($dry, 'processed_history_rows'));
    same($before, snapshot($db));
    $batches = runToEnd($service, $options);
    same(array(1, 1), array_column($batches, 'processed_history_rows'));
    same('STAFF-CACHE', value($db, 'SELECT contact_name FROM shop_order_log WHERE id=1'));
    same('STAFF-ACTOR', value($db, 'SELECT actor_name FROM shop_order_log WHERE id=1'));
    same('pay', value($db, 'SELECT action_id FROM shop_order_log WHERE id=1'));
    same($before['shop_order'], snapshot($db)['shop_order']);
    $insert->execute(array(3));
    same(1, $service->preview(365)['order_history']['rows_to_process']);
    same(1, array_sum(array_column(runToEnd($service, $options), 'processed_history_rows')));
    same(0, array_sum(array_column(runToEnd($service, $options), 'processed_history_rows')));
});

check('F05/F16: server plan frozen, stable progress, sequence and retry replay', function (): void {
    [$db, $service] = fixture();
    seedOrder($db, 1);
    seedOrder($db, 2);
    seedParam($db, 'email', 'PRIVATE-1', 1);
    seedParam($db, 'email', 'PRIVATE-2', 2);
    $session = array();
    $runs = new StandaloneRunSession($session, $service);
    $start = $runs->start(realOptions(), 'fixture-binding');
    same(2, $start['total']);
    rejects(fn() => $runs->batch($start['run_id'], 0, 'changed-binding'), 'изменились');
    rejects(fn() => $runs->batch($start['run_id'], 1, 'fixture-binding'), 'номер');
    $first = $runs->batch($start['run_id'], 0, 'fixture-binding');
    same($first, $runs->batch($start['run_id'], 0, 'fixture-binding'));
    $second = $runs->batch($start['run_id'], 1, 'fixture-binding');
    same(array(2, 2), array($first['total'], $second['total']));
    same(array(1, 2), array($first['progress'], $second['progress']));
    same($start['cutoff'], $second['cutoff']);
    same(false, array_key_exists('plan', $second));
    same(true, $second['done']);
    foreach (array($first, $second) as $batch) {
        $log = file_get_contents($batch['batch_log']);
        same(false, str_contains($log, 'PRIVATE-'));
        same(false, str_contains($log, 'ANONYMIZE'));
        same($start['run_id'], json_decode($log, true)['run_id']);
    }
});

check('dry-run and preview never create state or write DB', function (): void {
    [$db, $service] = fixture(false);
    seedContact($db, 1);
    seedOrder($db, 1, 1);
    seedParam($db, 'email', 'PRIVATE');
    $before = snapshot($db);
    $service->preview(365, array('anonymize_contacts' => true, 'contact_order_scope' => 'all'));
    runToEnd($service, array('dry_run' => true, 'anonymize_contacts' => true, 'contact_order_scope' => 'all'));
    same($before, snapshot($db));
    same(false, invokePrivate($service, 'tableExists', 'depersonalizer_state'));
    rejects(fn() => $service->createRun(array('dry_run' => false, 'anonymize_orders' => true)), 'ANONYMIZE');
    rejects(fn() => $service->createRun(realOptions(array('confirmation_phrase' => 'ANONYMIZE '))), 'ANONYMIZE');
    same(false, invokePrivate($service, 'tableExists', 'depersonalizer_state'));
    runToEnd($service, realOptions());
    same(true, invokePrivate($service, 'tableExists', 'depersonalizer_state'));
});

check('F13: schema columns, types, indexes and engines fail closed', function (): void {
    [$db, $service, $dir] = fixture();
    $db->exec('DROP TABLE wa_contact_emails');
    $db->exec('CREATE TABLE wa_contact_emails (contact_id INTEGER, email VARCHAR(255))' . ($db->mysql ? ' ENGINE=InnoDB' : ''));
    $service = new StandaloneDepersonalizer($db, $dir);
    rejects(fn() => $service->createRun(realOptions(array('anonymize_contacts' => true))), 'wa_contact_emails.id');
    rejects(fn() => $service->createRun(realOptions(array('anonymize_contacts' => true, 'dry_run' => true))), 'wa_contact_emails.id');
    [$db, $service, $dir] = fixture();
    $db->exec('DROP TABLE shop_order_params');
    $db->exec('CREATE TABLE shop_order_params (order_id INTEGER, name VARCHAR(64), value VARCHAR(255))' . ($db->mysql ? ' ENGINE=InnoDB' : ''));
    $service = new StandaloneDepersonalizer($db, $dir);
    rejects(fn() => $service->createRun(realOptions()), 'уникальный ключ');
    [$db, $service, $dir] = fixture();
    $db->exec($db->mysql ? 'DROP INDEX contact_id ON shop_order' : 'DROP INDEX contact_id');
    $service = new StandaloneDepersonalizer($db, $dir);
    rejects(fn() => $service->createRun(realOptions(array('anonymize_contacts' => true))), 'индекса');
    if ($db->mysql) {
        [$db, $service, $dir] = fixture();
        $db->exec('ALTER TABLE shop_order_params ENGINE=MyISAM');
        $service = new StandaloneDepersonalizer($db, $dir);
        rejects(fn() => $service->createRun(realOptions(array('dry_run' => true))), 'InnoDB');
        [$db, $service, $dir] = fixture();
        $db->exec('ALTER TABLE wa_contact_emails MODIFY email VARCHAR(10)');
        $service = new StandaloneDepersonalizer($db, $dir);
        rejects(fn() => $service->createRun(realOptions(array('anonymize_contacts' => true))), 'длина');
    }
});

check('optional tables stay optional; staff/login protection remains active', function (): void {
    [$db, $service, $dir] = fixture();
    foreach (array('wa_contact', 'wa_contact_emails', 'wa_contact_data', 'wa_contact_data_text',
        'wa_contact_addresses', 'shop_order_log', 'depersonalizer_state') as $table) {
        $db->exec('DROP TABLE ' . $table);
    }
    $service = new StandaloneDepersonalizer($db, $dir);
    same(array(), $service->preflight(false)['schema_errors']);
    same(false, $service->preview(365)['contact_catchup']['available']);
    same(false, $service->preview(365)['order_history']['exists']);
    seedOrder($db);
    seedParam($db, 'email', 'PRIVATE');
    same(array(1), ids(runToEnd($service, realOptions()), 'processed_orders'));
    rejects(fn() => $service->createRun(realOptions(array('anonymize_contacts' => true))), 'wa_contact');
    [$db, $service] = fixture();
    seedContact($db, 1);
    seedContact($db, 2);
    seedOrder($db, 1, 1);
    seedOrder($db, 2, 2);
    $db->exec("UPDATE wa_contact SET is_user=-1 WHERE id=1");
    $db->exec("UPDATE wa_contact SET login='fixture-login' WHERE id=2");
    $before = snapshot($db);
    $result = runToEnd($service, realOptions(array('anonymize_orders' => false, 'anonymize_contacts' => true, 'contact_order_scope' => 'all')));
    same(array(), ids($result, 'processed_contacts'));
    same($before, snapshot($db));
});

check('F14: logging failures before and after commit', function (): void {
    [$db, $service, $dir] = fixture();
    seedOrder($db);
    seedParam($db, 'email', 'PRIVATE');
    $plan = $service->createRun(realOptions());
    $before = snapshot($db);
    file_put_contents($dir . '/batches', 'fixture-file-not-directory');
    rejects(fn() => $service->runBatch($plan), 'каталог');
    same($before, snapshot($db));
    unlink($dir . '/batches');
    mkdir($dir . '/depersonalizer.log');
    rejects(fn() => $service->runBatch($plan), 'журнал');
    same($before, snapshot($db));
    rmdir($dir . '/depersonalizer.log');
    $db->afterCommit = function () use ($dir): void {
        foreach (glob($dir . '/batches/*/*.json') as $path) {
            unlink($path);
            mkdir($path);
        }
    };
    $result = $service->runBatch($plan);
    same(true, $result['committed']);
    same(1, count($result['warnings']));
    same('obezlicheno+order_1@obezlicheno.invalid', value($db, "SELECT value FROM shop_order_params WHERE name='email'"));
});

check('real-run lease, restart and strict placeholder patterns', function (): void {
    [$db, $service] = fixture();
    $one = $service->createRun(realOptions());
    rejects(fn() => $service->createRun(realOptions()), 'Другой реальный');
    rejects(fn() => $service->createRun(realOptions(array('run_id' => $one['run_id']))), 'Другой реальный');
    $service->finishRun('unrelated_run');
    same(1, (int)value($db, "SELECT COUNT(*) FROM depersonalizer_state WHERE entity_type='run_lock'"));
    $service->finishRun($one['lease_id']);
    $two = $service->createRun(realOptions());
    rejects(fn() => $service->runBatch($one), 'Другой реальный');
    $service->finishRun($two['lease_id']);
    foreach (array('DELETED', 'anon+contact_bill@example.invalid', 'anon-contact-real-phone', 'user@example.invalid') as $value) {
        same($value, invokePrivate($service, 'normalizeLegacyValue', $value));
    }
    same('Обезличен', invokePrivate($service, 'normalizeLegacyValue', 'Deleted'));
    same('#001, #002', invokePrivate($service, 'compactOrderIdentifierRanges', array('#001', '#002')));
    same('#1-#3, #7', invokePrivate($service, 'compactOrderIdentifierRanges', array('#1', '#2', '#3', '#7')));
});

check('MySQL 5.7: lock contact order range against concurrent newer insert', function () {
    [$db, $service] = fixture();
    if (!$db->mysql) {
        return false;
    }
    seedContact($db, 1);
    seedOrder($db, 1, 1);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    same(null, invokePrivate($service, 'getContactSkipReason', 1, '2020-01-01', true));
    $other = new PDO((string)getenv('DEPERSONALIZER_TEST_DSN'), 'root', (string)getenv('DEPERSONALIZER_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    rejects(fn() => seedOrder($other, 2, 1, '2099-01-01'), 'Lock wait timeout');
    $db->rollBack();
    seedOrder($other, 2, 1, '2099-01-01');
    same('has_newer_orders', invokePrivate($service, 'getContactSkipReason', 1, '2020-01-01'));
});

// Load only pure functions from index.php, never its HTTP/config/bootstrap code.
$index = file_get_contents(__DIR__ . '/../index.php');
preg_match_all('/^function (depersonalizer\w+)\([^\n]*\)[^\n]*\n\{.*?^\}/ms', $index, $functions, PREG_SET_ORDER);
foreach ($functions as $function) {
    eval($function[0]);
}
check('F03/F15/F18: production guard, token revocation, strict config', function (): void {
    $_SERVER['REQUEST_URI'] = '/index.php?fake=/test/';
    $_SERVER['SCRIPT_NAME'] = '/test/tools/depersonalizer/index.php';
    $_SERVER['HTTP_HOST'] = 'test.example.invalid';
    same(false, depersonalizerDetectEnvironment(array('database' => 'shop_live'))['can_run_real']);
    same(false, depersonalizerDetectEnvironment(array('database' => 'dtalke_shop', 'allow_prod' => 'false'))['can_run_real']);
    same(true, depersonalizerDetectEnvironment(array('database' => 'dtalke_test_shop'))['can_run_real']);
    same('/test/tools/depersonalizer/', depersonalizerSessionCookiePath());
    foreach (array('ACCESS_TOKEN', 'ACCESS_TOKEN_SHA256', 'ACCESS_TOKEN_HASH') as $key) {
        putenv('DEPERSONALIZER_' . $key);
    }
    $config = array('access_token' => 'synthetic-fixture-token');
    $_SESSION = array('depersonalizer_authenticated' => true, 'depersonalizer_authenticated_at' => time(),
        'depersonalizer_token_fingerprint' => depersonalizerTokenFingerprint($config));
    same(true, depersonalizerIsAuthorized($config));
    same(false, depersonalizerIsAuthorized(array('access_token' => 'rotated-fixture-token')));
    putenv('DEPERSONALIZER_ACCESS_TOKEN_SHA256=' . hash('sha256', 'environment-fixture'));
    same(false, depersonalizerVerifyAccessToken('synthetic-fixture-token', $config));
    same(true, depersonalizerVerifyAccessToken('environment-fixture', $config));
    putenv('DEPERSONALIZER_ACCESS_TOKEN_SHA256');
    $dir = sys_get_temp_dir() . '/depersonalizer-config-test-' . bin2hex(random_bytes(8));
    mkdir($dir);
    $path = $dir . '/config.local.php';
    file_put_contents($path, "<?php return array('database'=>'fixture_test_shop','user'=>'fixture','allow_prod'=>'false');");
    rejects(fn() => StandaloneConfigLoader::load($dir), 'allow_prod');
    file_put_contents($path, "<?php echo 'NEVER-SHOW'; return array();");
    ob_start();
    rejects(fn() => StandaloneConfigLoader::load($dir), 'без вывода');
    same('', ob_get_clean());
    file_put_contents($path, '<?php broken syntax');
    rejects(fn() => StandaloneConfigLoader::load($dir), 'синтаксис');
    file_put_contents($path, "<?php return array('db'=>array('database'=>'fixture_test_shop','user'=>'fixture'),'allow_prod'=>false);");
    same('fixture_test_shop', StandaloneConfigLoader::load($dir)['database']);
    same(false, StandaloneConfigLoader::load($dir)['allow_prod']);
    $dsnConfig = StandaloneConfigLoader::load($dir);
    $dsnConfig['database'] = 'fixture_test_shop;dbname=live';
    rejects(fn() => StandaloneConfigLoader::buildDsn($dsnConfig), 'DSN');
    $dsnConfig['database'] = 'fixture_test_shop';
    $dsnConfig['charset'] = 'latin1';
    rejects(fn() => StandaloneConfigLoader::buildDsn($dsnConfig), 'UTF-8');
    unlink($path);
    rmdir($dir);
});

echo 'OK: ' . $passed . ' regression groups; skipped=' . $skipped .
    '; driver=' . (getenv('DEPERSONALIZER_TEST_DSN') ? 'mysql' : 'sqlite-adapter') . PHP_EOL;
