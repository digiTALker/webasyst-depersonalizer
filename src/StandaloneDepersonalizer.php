<?php
declare(strict_types=1);

final class StandaloneDepersonalizer
{
    private const ORDER_PROCESSED_KEY = '_depersonalizer_ext_processed';
    private const ORDER_PROCESSED_AT_KEY = '_depersonalizer_ext_processed_at';

    private const CONTACT_PROCESSED_KEY = '_depersonalizer_ext_processed';
    private const CONTACT_PROCESSED_AT_KEY = '_depersonalizer_ext_processed_at';
    private const CONTACT_PARAMS_APP_ID = 'shop';

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $logDir;

    /** @var string */
    private $logFile;

    /** @var array<string, bool> */
    private $tableExistsCache = array();

    /** @var array<string, array<int, string>> */
    private $tableColumnsCache = array();

    /** @var bool|null */
    private $contactParamsHasAppId = null;

    /** @var array<int, string> */
    private $exactPiiKeys = array(
        'firstname',
        'middlename',
        'lastname',
        'name',
        'company',
        'email',
        'phone',
        'address',
        'zip',
        'city',
        'region',
        'country',
        'street',
        'house',
        'comment',
        'customer_comment',
        'ip',
        'user_agent',
    );

    /** @var array<int, string> */
    private $piiWildcardPrefixes = array(
        'shipping_',
        'billing_',
        'utm_',
    );

    /** @var array<int, string> */
    private $piiRegex = array(
        '/name/i',
        '/email/i',
        '/phone/i',
        '/address/i',
        '/zip/i',
        '/city/i',
        '/region/i',
        '/country/i',
        '/street/i',
        '/house/i',
        '/ip/i',
        '/user_agent/i',
        '/comment/i',
    );

    public function __construct(PDO $pdo, string $logDir)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . 'depersonalizer.log';
        $this->ensureDirectory($this->logDir);
    }

    /**
     * Verify required schema objects and report optional compatibility data.
     *
     * @return array<string, mixed>
     */
    public function preflight(): array
    {
        $required = array(
            'shop_order',
            'shop_order_params',
            'wa_contact',
            'wa_contact_params',
        );

        $missing = array();
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return array(
            'missing_required_tables' => $missing,
            'optional_tables' => array(
                'wa_contact_emails' => $this->tableExists('wa_contact_emails'),
                'wa_contact_data'   => $this->tableExists('wa_contact_data'),
            ),
            'safe_mode_notes' => array(
                'No schema changes are applied.',
                'Order and contact rows are updated in-place; IDs and relations are preserved.',
                'Each record is marked by namespaced keys to avoid repeated rewrites.',
                'Address rows are never deleted by this standalone tool.',
            ),
        );
    }

    /**
     * Dry-run preview: count old orders and detect candidate PII keys.
     *
     * @param int $days
     * @return array<string, mixed>
     */
    public function preview(int $days): array
    {
        $days = $this->normalizeDays($days);
        $cutoff = $this->cutoff($days);

        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS cnt FROM shop_order WHERE create_datetime < :cutoff'
        );
        $countStmt->execute(array(':cutoff' => $cutoff));
        $totalOrders = (int)$countStmt->fetchColumn();

        $keyStmt = $this->pdo->prepare(
            'SELECT DISTINCT op.name
             FROM shop_order_params op
             INNER JOIN shop_order o ON o.id = op.order_id
             WHERE o.create_datetime < :cutoff'
        );
        $keyStmt->execute(array(':cutoff' => $cutoff));

        $keys = array();
        while (($name = $keyStmt->fetchColumn()) !== false) {
            $name = (string)$name;
            if ($this->isPiiKey($name)) {
                $keys[] = $name;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return array(
            'days' => $days,
            'cutoff' => $cutoff,
            'total_orders' => $totalOrders,
            'candidate_keys' => $keys,
        );
    }

    /**
     * Execute one batch.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runBatch(array $options): array
    {
        $days = $this->normalizeDays((int)($options['days'] ?? 365));
        $limit = $this->normalizeLimit((int)($options['limit'] ?? 200));
        $cursor = max(0, (int)($options['cursor'] ?? 0));

        $keepGeo = !empty($options['keep_geo']);
        $wipeComments = !empty($options['wipe_comments']);
        $anonymizeContacts = !empty($options['anonymize_contacts']);
        $dryRun = !empty($options['dry_run']);

        $includeKeys = $this->normalizeIncludeKeys($options['include_keys'] ?? array());
        $includeMap = array_fill_keys($includeKeys, true);

        $cutoff = $this->cutoff($days);
        $total = $this->countOrdersOlderThan($cutoff);

        $orders = $this->fetchOrdersBatch($cutoff, $cursor, $limit);
        if (!$orders) {
            return array(
                'cutoff' => $cutoff,
                'cursor' => $cursor,
                'done' => true,
                'batch_count' => 0,
                'progress' => $this->countOrdersUpToCursor($cutoff, $cursor),
                'total' => $total,
                'processed_orders' => array(),
                'skipped_orders' => array(),
                'processed_contacts' => array(),
                'skipped_contacts' => array(),
                'dry_run' => $dryRun,
                'batch_log' => null,
            );
        }

        $processedOrders = array();
        $skippedOrders = array();
        $processedContacts = array();
        $skippedContacts = array();
        $contactIds = array();

        if (!$dryRun) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($orders as $order) {
                $orderId = (int)$order['id'];
                $contactId = (int)$order['contact_id'];

                $params = $this->fetchOrderParams($orderId);

                if ($this->isOrderAlreadyProcessed($params)) {
                    $skippedOrders[$orderId] = 'already_processed';
                    continue;
                }

                if ($keepGeo && !$dryRun) {
                    $this->preserveGeoSnapshot($orderId, $params);
                }

                foreach ($params as $key => $value) {
                    if ($key === self::ORDER_PROCESSED_KEY || $key === self::ORDER_PROCESSED_AT_KEY) {
                        continue;
                    }

                    if ($includeMap && !isset($includeMap[$key])) {
                        continue;
                    }

                    if (!$this->isPiiKey($key)) {
                        continue;
                    }

                    if (!$dryRun) {
                        $maskedValue = $this->maskOrderParam($key, (string)$value, $orderId);
                        $this->setOrderParam($orderId, $key, $maskedValue);
                    }
                }

                if ($wipeComments && !$dryRun) {
                    if (array_key_exists('comment', $params)) {
                        $this->setOrderParam($orderId, 'comment', '');
                    }
                    if (array_key_exists('customer_comment', $params)) {
                        $this->setOrderParam($orderId, 'customer_comment', '');
                    }
                }

                if (!$dryRun) {
                    $this->setOrderParam($orderId, self::ORDER_PROCESSED_KEY, '1');
                    $this->setOrderParam($orderId, self::ORDER_PROCESSED_AT_KEY, date('Y-m-d H:i:s'));
                }

                $processedOrders[] = $orderId;

                if ($anonymizeContacts && $contactId > 0) {
                    $contactIds[$contactId] = true;
                }
            }

            if ($anonymizeContacts && $contactIds) {
                $contactResult = $dryRun
                    ? $this->simulateContacts(array_keys($contactIds), $cutoff)
                    : $this->processContacts(array_keys($contactIds), $cutoff);

                $processedContacts = $contactResult['processed'];
                $skippedContacts = $contactResult['skipped'];
            }

            if (!$dryRun) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->log('error', 'Batch failed', array(
                'cursor' => $cursor,
                'exception' => $error->getMessage(),
            ));
            throw $error;
        }

        $nextCursor = (int)$orders[count($orders) - 1]['id'];
        $done = !$this->hasOrdersAfterCursor($cutoff, $nextCursor);
        $progress = $this->countOrdersUpToCursor($cutoff, $nextCursor);

        $batchPayload = array(
            'executed_at' => date('c'),
            'dry_run' => $dryRun,
            'options' => array(
                'days' => $days,
                'limit' => $limit,
                'cursor' => $cursor,
                'keep_geo' => $keepGeo,
                'wipe_comments' => $wipeComments,
                'anonymize_contacts' => $anonymizeContacts,
                'include_keys' => $includeKeys,
            ),
            'cutoff' => $cutoff,
            'result' => array(
                'processed_orders' => $processedOrders,
                'skipped_orders' => $skippedOrders,
                'processed_contacts' => $processedContacts,
                'skipped_contacts' => $skippedContacts,
            ),
        );

        $batchLog = $this->writeBatchLog($batchPayload);

        $this->log('info', 'Batch completed', array(
            'cursor_from' => $cursor,
            'cursor_to' => $nextCursor,
            'dry_run' => $dryRun,
            'processed_orders' => count($processedOrders),
            'processed_contacts' => count($processedContacts),
            'batch_log' => $batchLog,
        ));

        return array(
            'cutoff' => $cutoff,
            'cursor' => $nextCursor,
            'done' => $done,
            'batch_count' => count($orders),
            'progress' => $progress,
            'total' => $total,
            'processed_orders' => $processedOrders,
            'skipped_orders' => $skippedOrders,
            'processed_contacts' => $processedContacts,
            'skipped_contacts' => $skippedContacts,
            'dry_run' => $dryRun,
            'batch_log' => $batchLog,
        );
    }

    /**
     * @param string $cutoff
     * @param int $cursor
     * @param int $limit
     * @return array<int, array{id:int, contact_id:int}>
     */
    private function fetchOrdersBatch(string $cutoff, int $cursor, int $limit): array
    {
        $sql = 'SELECT id, contact_id
                FROM shop_order
                WHERE create_datetime < :cutoff
                  AND id > :cursor
                ORDER BY id ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['contact_id'] = (int)$row['contact_id'];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param int $orderId
     * @return array<string, string>
     */
    private function fetchOrderParams(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT name, value FROM shop_order_params WHERE order_id = :order_id'
        );
        $stmt->execute(array(':order_id' => $orderId));

        $params = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $params[(string)$row['name']] = (string)$row['value'];
        }

        return $params;
    }

    /**
     * @param array<string, string> $params
     */
    private function isOrderAlreadyProcessed(array $params): bool
    {
        return isset($params[self::ORDER_PROCESSED_KEY]) && (string)$params[self::ORDER_PROCESSED_KEY] === '1';
    }

    /**
     * @param int $orderId
     * @param string $name
     * @param string $value
     */
    private function setOrderParam(int $orderId, string $name, string $value): void
    {
        $sql = 'INSERT INTO shop_order_params (order_id, name, value)
                VALUES (:order_id, :name, :value)
                ON DUPLICATE KEY UPDATE value = VALUES(value)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':order_id' => $orderId,
            ':name' => $name,
            ':value' => $value,
        ));
    }

    /**
     * Save snapshot geo_* params based on existing country/region/city style keys.
     *
     * @param int $orderId
     * @param array<string, string> $params
     */
    private function preserveGeoSnapshot(int $orderId, array $params): void
    {
        $geoKeys = array('country', 'region', 'city');
        $prefixes = array('', 'shipping_', 'billing_');

        foreach ($geoKeys as $geoKey) {
            foreach ($prefixes as $prefix) {
                $sourceKey = $prefix . $geoKey;
                if (!empty($params[$sourceKey])) {
                    $this->setOrderParam($orderId, 'geo_' . $geoKey, (string)$params[$sourceKey]);
                    break;
                }
            }
        }
    }

    private function isPiiKey(string $key): bool
    {
        if (in_array($key, $this->exactPiiKeys, true)) {
            return true;
        }

        foreach ($this->piiWildcardPrefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        foreach ($this->piiRegex as $regex) {
            if (preg_match($regex, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    private function maskOrderParam(string $key, string $value, int $orderId): string
    {
        if (preg_match('/email/i', $key) === 1) {
            return 'anon+' . $orderId . '@example.invalid';
        }
        if (preg_match('/phone/i', $key) === 1) {
            return 'anon-' . sha1((string)$orderId);
        }
        if (preg_match('/(firstname|middlename|lastname|name|company)/i', $key) === 1) {
            return 'Deleted';
        }
        if (preg_match('/ip/i', $key) === 1) {
            return '0.0.0.0';
        }
        if (preg_match('/user_agent/i', $key) === 1) {
            return 'unknown';
        }
        if (preg_match('/(country|region|city|address|street|house|zip)/i', $key) === 1) {
            return '';
        }

        return '';
    }

    /**
     * @param array<int, int> $contactIds
     * @param string $cutoff
     * @return array{processed: array<int, int>, skipped: array<int, string>}
     */
    private function simulateContacts(array $contactIds, string $cutoff): array
    {
        $processed = array();
        $skipped = array();

        foreach ($contactIds as $contactId) {
            $reason = $this->getContactSkipReason($contactId, $cutoff);
            if ($reason !== null) {
                $skipped[$contactId] = $reason;
                continue;
            }
            $processed[] = $contactId;
        }

        return array('processed' => $processed, 'skipped' => $skipped);
    }

    /**
     * @param array<int, int> $contactIds
     * @param string $cutoff
     * @return array{processed: array<int, int>, skipped: array<int, string>}
     */
    private function processContacts(array $contactIds, string $cutoff): array
    {
        $processed = array();
        $skipped = array();

        foreach ($contactIds as $contactId) {
            $reason = $this->getContactSkipReason($contactId, $cutoff);
            if ($reason !== null) {
                $skipped[$contactId] = $reason;
                continue;
            }

            $contactStmt = $this->pdo->prepare(
                'UPDATE wa_contact
                 SET firstname = :firstname, middlename = :middlename, lastname = :lastname
                 WHERE id = :contact_id'
            );
            $contactStmt->execute(array(
                ':firstname' => 'Deleted',
                ':middlename' => '',
                ':lastname' => 'Deleted',
                ':contact_id' => $contactId,
            ));

            if ($this->tableExists('wa_contact_emails')) {
                $emailStmt = $this->pdo->prepare(
                    'UPDATE wa_contact_emails
                     SET email = :email
                     WHERE contact_id = :contact_id'
                );
                $emailStmt->execute(array(
                    ':email' => 'anon+' . $contactId . '@example.invalid',
                    ':contact_id' => $contactId,
                ));
            }

            if ($this->tableExists('wa_contact_data')) {
                $phoneStmt = $this->pdo->prepare(
                    "UPDATE wa_contact_data
                     SET value = :value
                     WHERE contact_id = :contact_id
                       AND (field = 'phone' OR field LIKE 'phone.%')"
                );
                $phoneStmt->execute(array(
                    ':value' => 'anon-' . sha1((string)$contactId),
                    ':contact_id' => $contactId,
                ));
            }

            $this->setContactParam($contactId, self::CONTACT_PROCESSED_KEY, '1');
            $this->setContactParam($contactId, self::CONTACT_PROCESSED_AT_KEY, date('Y-m-d H:i:s'));

            $processed[] = $contactId;
        }

        return array('processed' => $processed, 'skipped' => $skipped);
    }

    private function getContactSkipReason(int $contactId, string $cutoff): ?string
    {
        $existsStmt = $this->pdo->prepare('SELECT 1 FROM wa_contact WHERE id = :contact_id LIMIT 1');
        $existsStmt->execute(array(':contact_id' => $contactId));
        if (!$existsStmt->fetchColumn()) {
            return 'contact_missing';
        }

        $hasNewerStmt = $this->pdo->prepare(
            'SELECT 1
             FROM shop_order
             WHERE contact_id = :contact_id
               AND create_datetime >= :cutoff
             LIMIT 1'
        );
        $hasNewerStmt->execute(array(
            ':contact_id' => $contactId,
            ':cutoff' => $cutoff,
        ));
        if ($hasNewerStmt->fetchColumn()) {
            return 'has_newer_orders';
        }

        if ($this->isContactAlreadyProcessed($contactId)) {
            return 'already_processed';
        }

        return null;
    }

    private function isContactAlreadyProcessed(int $contactId): bool
    {
        if ($this->contactParamsHasAppId === null) {
            $this->contactParamsHasAppId = $this->tableHasColumn('wa_contact_params', 'app_id');
        }

        if ($this->contactParamsHasAppId) {
            $stmt = $this->pdo->prepare(
                "SELECT 1
                 FROM wa_contact_params
                 WHERE contact_id = :contact_id
                   AND app_id = :app_id
                   AND name = :name
                   AND value = '1'
                 LIMIT 1"
            );
            $stmt->execute(array(
                ':contact_id' => $contactId,
                ':app_id' => self::CONTACT_PARAMS_APP_ID,
                ':name' => self::CONTACT_PROCESSED_KEY,
            ));
            return (bool)$stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM wa_contact_params
             WHERE contact_id = :contact_id
               AND name = :name
               AND value = '1'
             LIMIT 1"
        );
        $stmt->execute(array(
            ':contact_id' => $contactId,
            ':name' => self::CONTACT_PROCESSED_KEY,
        ));

        return (bool)$stmt->fetchColumn();
    }

    private function setContactParam(int $contactId, string $name, string $value): void
    {
        if ($this->contactParamsHasAppId === null) {
            $this->contactParamsHasAppId = $this->tableHasColumn('wa_contact_params', 'app_id');
        }

        if ($this->contactParamsHasAppId) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO wa_contact_params (contact_id, app_id, name, value)
                 VALUES (:contact_id, :app_id, :name, :value)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)'
            );
            $stmt->execute(array(
                ':contact_id' => $contactId,
                ':app_id' => self::CONTACT_PARAMS_APP_ID,
                ':name' => $name,
                ':value' => $value,
            ));
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO wa_contact_params (contact_id, name, value)
             VALUES (:contact_id, :name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute(array(
            ':contact_id' => $contactId,
            ':name' => $name,
            ':value' => $value,
        ));
    }

    private function cutoff(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
    }

    private function countOrdersOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order WHERE create_datetime < :cutoff');
        $stmt->execute(array(':cutoff' => $cutoff));
        return (int)$stmt->fetchColumn();
    }

    private function countOrdersUpToCursor(string $cutoff, int $cursor): int
    {
        if ($cursor <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order
             WHERE create_datetime < :cutoff
               AND id <= :cursor'
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function hasOrdersAfterCursor(string $cutoff, int $cursor): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM shop_order
             WHERE create_datetime < :cutoff
               AND id > :cursor
             LIMIT 1'
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    private function normalizeDays(int $days): int
    {
        if ($days < 1) {
            return 1;
        }
        if ($days > 36500) {
            return 36500;
        }
        return $days;
    }

    private function normalizeLimit(int $limit): int
    {
        if ($limit < 1) {
            return 1;
        }
        if ($limit > 1000) {
            return 1000;
        }
        return $limit;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function normalizeIncludeKeys($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $keys = array();
        foreach ($raw as $value) {
            $name = trim((string)$value);
            if ($name === '') {
                continue;
            }
            $keys[$name] = true;
        }

        return array_keys($keys);
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $stmt->execute(array(':table_name' => $table));

        $exists = ((int)$stmt->fetchColumn() > 0);
        $this->tableExistsCache[$table] = $exists;

        return $exists;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }

        if (!array_key_exists($table, $this->tableColumnsCache)) {
            $safeTable = $this->quoteIdentifier($table);
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $safeTable);
            $columns = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = (string)$row['Field'];
            }
            $this->tableColumnsCache[$table] = $columns;
        }

        return in_array($column, $this->tableColumnsCache[$table], true);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe identifier: ' . $identifier);
        }

        return '`' . $identifier . '`';
    }

    /**
     * @param array<string, mixed> $payload
     * @return string
     */
    private function writeBatchLog(array $payload): string
    {
        $dateDir = $this->logDir . DIRECTORY_SEPARATOR . 'batches' . DIRECTORY_SEPARATOR . date('Y-m-d');
        $this->ensureDirectory($dateDir);

        $fileName = 'batch-' . date('H-i-s') . '-' . mt_rand(1000, 9999) . '.json';
        $path = $dateDir . DIRECTORY_SEPARATOR . $fileName;

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $path;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = array()): void
    {
        $line = array(
            'time' => date('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        );

        file_put_contents(
            $this->logFile,
            json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}


