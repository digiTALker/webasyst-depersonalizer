<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/StandaloneDepersonalizer.php';
require_once __DIR__ . '/../src/StandaloneConfigLoader.php';
require_once __DIR__ . '/../src/StandaloneRunSession.php';

// The SQLite adapter tests PHP control flow only. The same suite also runs on MySQL 5.7 in CI.
final class FixturePdo extends PDO
{
    public bool $mysql;
    public array $queries = array();
    public $afterCommit = null;

    public function __construct()
    {
        $dsn = (string)getenv('DEPERSONALIZER_TEST_DSN');
        $this->mysql = $dsn !== '';
        if ($this->mysql) {
            if (getenv('DEPERSONALIZER_TEST_MYSQL') !== '1' ||
                !preg_match('/^mysql:host=(127[.]0[.]0[.]1|localhost|mysql);port=[0-9]+;dbname=depersonalizer_test_suite$/D', $dsn)) {
                throw new RuntimeException('Tests require an explicitly enabled, isolated loopback test database.');
            }
            parent::__construct($dsn, 'root', (string)getenv('DEPERSONALIZER_TEST_PASSWORD'),
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false));
            if ($this->query('SELECT DATABASE()')->fetchColumn() !== 'depersonalizer_test_suite') {
                throw new RuntimeException('Refusing to reset an unrecognized database.');
            }
            foreach (array('shop_order', 'shop_order_params', 'wa_contact', 'wa_contact_emails',
                'wa_contact_data', 'wa_contact_data_text', 'wa_contact_addresses', 'shop_order_log', 'depersonalizer_state') as $table) {
                $this->exec('DROP TABLE IF EXISTS ' . $table);
            }
        } else {
            parent::__construct('sqlite::memory:');
            $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->sqliteCreateFunction('CONCAT', fn(...$parts) => implode('', $parts));
            $this->sqliteCreateFunction('SHA1', fn($value) => sha1((string)$value));
            $this->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
            $this->sqliteCreateFunction('REGEXP', fn($pattern, $value) => preg_match('~' . $pattern . '~D', (string)$value));
            $this->sqliteCreateFunction('TIMESTAMPDIFF', fn($unit, $from, $to) => strtotime($to) - strtotime($from));
        }
    }

    private function translate(string $sql): string
    {
        $this->queries[] = $sql;
        if ($this->mysql) {
            return $sql;
        }
        if (str_contains($sql, 'information_schema.tables')) {
            if (str_contains($sql, 'SELECT ENGINE')) {
                return "SELECT 'InnoDB' FROM sqlite_master WHERE name = :table";
            }
            return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table_name";
        }
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/i', $sql, $m)) {
            return "SELECT name AS Field, type AS Type, CASE WHEN \"notnull\" = 0 THEN 'YES' ELSE 'NO' END AS \"Null\"
                FROM pragma_table_info('" . $m[1] . "')";
        }
        if (preg_match('/^SHOW INDEX FROM `([a-z_]+)`$/i', $sql, $m)) {
            return "SELECT 'PRIMARY' AS Key_name, 0 AS Non_unique, pk AS Seq_in_index, name AS Column_name, NULL AS Sub_part
                FROM pragma_table_info('" . $m[1] . "') WHERE pk > 0
                UNION ALL SELECT il.name, 1 - il.\"unique\", ii.seqno + 1, ii.name, NULL
                FROM pragma_index_list('" . $m[1] . "') il JOIN pragma_index_info(il.name) ii WHERE il.origin <> 'pk'";
        }
        if (str_starts_with($sql, 'EXPLAIN ')) {
            return 'SELECT 1';
        }
        if (str_starts_with($sql, 'SET TRANSACTION ')) {
            return 'SELECT 1';
        }
        $sql = preg_replace('/\\bBINARY\\s+/', '', $sql);
        $sql = preg_replace('/FORCE INDEX \\(`[a-z_]+`\\)/i', '', $sql);
        $sql = str_replace(' FOR UPDATE', '', $sql);
        $sql = str_replace('TIMESTAMPDIFF(SECOND,', "TIMESTAMPDIFF('SECOND',", $sql);
        $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        $sql = preg_replace('/,\\s*KEY (run_id|processed_at) \\([a-z_]+\\)/', '', $sql);
        $sql = preg_replace('/ ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/i', '', $sql);
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $target = str_contains($sql, 'INSERT INTO depersonalizer_state') ? 'entity_type, entity_id' : 'order_id, name';
            $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT (' . $target . ') DO UPDATE SET', $sql);
            $sql = preg_replace('/VALUES\\((\\w+)\\)/', 'excluded.$1', $sql);
        }
        return $sql;
    }

    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = $this->translate($query);
        return $fetchMode === null ? parent::query($sql) : parent::query($sql, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->translate($statement));
    }

    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->afterCommit !== null) {
            ($this->afterCommit)();
        }
        return $result;
    }
}

function fixture(bool $withState = true): array
{
    $db = new FixturePdo();
    $suffix = $db->mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $definitions = array(
        'shop_order' => 'id INTEGER PRIMARY KEY, contact_id INTEGER, create_datetime DATETIME NOT NULL,
            comment TEXT, total DECIMAL(15,4) DEFAULT 100, state_id VARCHAR(32) DEFAULT \'paid\'',
        'shop_order_params' => 'order_id INTEGER, name VARCHAR(64), value VARCHAR(255), PRIMARY KEY(order_id,name)',
        'wa_contact' => 'id INTEGER PRIMARY KEY, name VARCHAR(150), firstname VARCHAR(50), lastname VARCHAR(50),
            company VARCHAR(150), is_staff INTEGER DEFAULT 0, is_user INTEGER DEFAULT 0, login VARCHAR(32)',
        'wa_contact_emails' => 'id INTEGER PRIMARY KEY, contact_id INTEGER, email VARCHAR(255), status VARCHAR(32)',
        'wa_contact_data' => 'id INTEGER PRIMARY KEY, contact_id INTEGER, field VARCHAR(32), value VARCHAR(255)',
        'wa_contact_data_text' => 'id INTEGER PRIMARY KEY, contact_id INTEGER, field VARCHAR(32), value TEXT',
        'wa_contact_addresses' => 'id INTEGER PRIMARY KEY, contact_id INTEGER, street VARCHAR(255), city VARCHAR(255)',
        'shop_order_log' => 'id INTEGER PRIMARY KEY, order_id INTEGER, contact_id INTEGER, action_id VARCHAR(32),
            datetime DATETIME, text TEXT, contact_name VARCHAR(150), actor_name VARCHAR(150)',
        'depersonalizer_state' => 'entity_type VARCHAR(32), entity_id INTEGER, processed_at DATETIME,
            run_id VARCHAR(80), note VARCHAR(255), payload TEXT, PRIMARY KEY(entity_type,entity_id)',
    );
    foreach ($definitions as $table => $definition) {
        if (!$withState && $table === 'depersonalizer_state') {
            continue;
        }
        $db->exec('CREATE TABLE ' . $table . ' (' . $definition . ')' . $suffix);
    }
    $db->exec('CREATE INDEX contact_id ON shop_order (contact_id)');
    $logDir = sys_get_temp_dir() . '/depersonalizer-tests-' . bin2hex(random_bytes(8));
    $service = new StandaloneDepersonalizer($db, $logDir);
    $service->preflight(false);
    return array($db, $service, $logDir);
}

function seedOrder(PDO $db, int $id = 1, int $contact = 0, string $date = '2000-01-01 00:00:00'): void
{
    $db->prepare('INSERT INTO shop_order (id,contact_id,create_datetime,comment) VALUES (?,?,?,?)')
        ->execute(array($id, $contact, $date, 'PRIVATE-FIXTURE-COMMENT'));
}

function seedParam(PDO $db, string $name, string $value, int $order = 1): void
{
    $db->prepare('INSERT INTO shop_order_params VALUES (?,?,?)')->execute(array($order, $name, $value));
}

function realOptions(array $overrides = array()): array
{
    return array_replace(array('days' => 365, 'limit' => 1, 'dry_run' => false, 'backup_confirmed' => true,
        'confirmation_phrase' => 'ANONYMIZE', 'anonymize_orders' => true,
        'run_id' => 'test_' . bin2hex(random_bytes(8)), 'include_keys' => array('email')), $overrides);
}

function value(PDO $db, string $sql): mixed
{
    return $db->query($sql)->fetchColumn();
}

function invokePrivate(object $object, string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
}

function runToEnd(StandaloneDepersonalizer $service, array $options): array
{
    $plan = $service->createRun($options);
    $results = array();
    do {
        $result = $service->runBatch($plan);
        $plan = $result['plan'];
        $results[] = $result;
        if (count($results) > 50) {
            throw new RuntimeException('Run failed to terminate.');
        }
    } while (!$result['done']);
    return $results;
}

function snapshot(PDO $db): array
{
    $result = array();
    foreach (array('shop_order', 'shop_order_params', 'wa_contact', 'wa_contact_emails',
        'wa_contact_data', 'wa_contact_data_text', 'wa_contact_addresses', 'shop_order_log') as $table) {
        $result[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
