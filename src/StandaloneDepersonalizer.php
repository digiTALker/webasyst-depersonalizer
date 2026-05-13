<?php
declare(strict_types=1);

final class StandaloneDepersonalizer
{
    private const ORDER_PROCESSED_KEY = '_depersonalizer_ext_processed';
    private const ORDER_PROCESSED_AT_KEY = '_depersonalizer_ext_processed_at';

    private const STATE_TABLE = 'depersonalizer_state';

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

    /** @var bool */
    private $stateTableReady = false;

    /** @var string */
    private $runId;

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
        'flat',
        'comment',
        'customer_comment',
        'ip',
        'user_agent',
    );

    /** @var array<int, string> */
    private $piiWildcardPrefixes = array(
        'shipping_',
        'billing_',
        'address_',
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
        '/flat/i',
        '/(^|[_\-.])ip($|[_\-.])|ip_address|remote_addr/i',
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
        $this->runId = date('YmdHis') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Verify required schema objects and report optional compatibility data.
     *
     * @return array<string, mixed>
     */
    public function preflight(bool $setupStateTable = true): array
    {
        $required = array(
            'shop_order',
            'shop_order_params',
        );

        $missing = array();
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        $stateError = null;
        if (!$missing && $setupStateTable) {
            try {
                $this->ensureStateTable();
            } catch (Throwable $error) {
                $stateError = $error->getMessage();
                $this->stateTableReady = false;
            }
        } elseif (!$missing) {
            $this->stateTableReady = $this->tableExists(self::STATE_TABLE);
        }

        return array(
            'missing_required_tables' => $missing,
            'optional_tables' => array(
                'wa_contact'          => $this->tableExists('wa_contact'),
                'wa_contact_emails'   => $this->tableExists('wa_contact_emails'),
                'wa_contact_data'     => $this->tableExists('wa_contact_data'),
                'wa_contact_data_text' => $this->tableExists('wa_contact_data_text'),
                'wa_contact_addresses' => $this->tableExists('wa_contact_addresses'),
                'wa_contact_params'   => $this->tableExists('wa_contact_params'),
                'state_table'         => self::STATE_TABLE,
                'state_table_ready'   => $this->stateTableReady,
                'state_table_error'   => $stateError,
            ),
            'safe_mode_notes' => array(
                'Only this tool owned depersonalizer_state table is created automatically.',
                'Order and contact rows are updated in-place; IDs and relations are preserved.',
                'Orders are marked in shop_order_params; contacts are marked in depersonalizer_state.',
                'wa_contact_params is optional and is not required for page load or contact state.',
                'Address rows are never deleted by this standalone tool.',
            ),
        );
    }

    public function getMysqlVersion(): string
    {
        return (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
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
            'SELECT COUNT(*) AS cnt
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )'
        );
        $countStmt->execute(array(
            ':cutoff' => $cutoff,
            ':processed_key' => self::ORDER_PROCESSED_KEY,
            ':processed_value' => '1',
        ));
        $totalOrders = (int)$countStmt->fetchColumn();

        $keyStmt = $this->pdo->prepare(
            'SELECT DISTINCT op.name
             FROM shop_order_params op
             INNER JOIN shop_order o ON o.id = op.order_id
             WHERE o.create_datetime < :cutoff
               AND op.name NOT IN (:processed_key, :processed_at_key)
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key_done
                     AND done.value = :processed_value
               )'
        );
        $keyStmt->execute(array(
            ':cutoff' => $cutoff,
            ':processed_key' => self::ORDER_PROCESSED_KEY,
            ':processed_at_key' => self::ORDER_PROCESSED_AT_KEY,
            ':processed_key_done' => self::ORDER_PROCESSED_KEY,
            ':processed_value' => '1',
        ));

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

        if (!$dryRun) {
            $backupConfirmed = !empty($options['backup_confirmed']);
            $confirmationPhrase = trim((string)($options['confirmation_phrase'] ?? ''));
            if (!$backupConfirmed || $confirmationPhrase !== 'ANONYMIZE') {
                throw new RuntimeException('Real anonymization requires backup confirmation and exact ANONYMIZE phrase.');
            }
        }

        if ($anonymizeContacts && !$dryRun && !$this->stateTableReady) {
            $this->ensureStateTable();
        }

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
                'run_id' => $this->runId,
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

                    if ($this->isCommentKey($key) && !$wipeComments && !isset($includeMap[$key])) {
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
        $progress = max(0, $total - $this->countOrdersAfterCursor($cutoff, $nextCursor));

        $batchPayload = array(
            'run_id' => $this->runId,
            'timestamp' => date('c'),
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
            'processed_orders' => $processedOrders,
            'skipped_orders' => $skippedOrders,
            'processed_contacts' => $processedContacts,
            'skipped_contacts' => $skippedContacts,
            'selected_include_keys' => $includeKeys,
        );

        $batchLog = $this->writeBatchLog($batchPayload);

        $this->log('info', 'Batch completed', array(
            'cursor_from' => $cursor,
            'cursor_to' => $nextCursor,
            'dry_run' => $dryRun,
            'processed_orders' => count($processedOrders),
            'processed_contacts' => count($processedContacts),
            'skipped_orders' => count($skippedOrders),
            'skipped_contacts' => count($skippedContacts),
            'run_id' => $this->runId,
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
            'run_id' => $this->runId,
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
                FROM shop_order o
                WHERE o.create_datetime < :cutoff
                  AND o.id > :cursor
                  AND NOT EXISTS (
                      SELECT 1
                      FROM shop_order_params done
                      WHERE done.order_id = o.id
                        AND done.name = :processed_key
                        AND done.value = :processed_value
                  )
                ORDER BY id ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':processed_key', self::ORDER_PROCESSED_KEY, PDO::PARAM_STR);
        $stmt->bindValue(':processed_value', '1', PDO::PARAM_STR);
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
        if ($this->isTechnicalParamKey($key)) {
            return false;
        }

        if (in_array($key, $this->exactPiiKeys, true)) {
            return true;
        }

        foreach ($this->piiWildcardPrefixes as $prefix) {
            if (strpos($key, $prefix) === 0 && $this->keyHasPersonalFragment($key)) {
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

    private function isTechnicalParamKey(string $key): bool
    {
        return preg_match('/(^|_)(id|code|plugin|module|rate|method|currency|tax|total|price|amount|sku|quantity|status|state|workflow)$/i', $key) === 1;
    }

    private function keyHasPersonalFragment(string $key): bool
    {
        return preg_match(
            '/name|company|email|phone|address|zip|city|region|country|street|house|flat|comment|user_agent|(^|[_\-.])ip($|[_\-.])|ip_address|remote_addr/i',
            $key
        ) === 1;
    }

    private function isCommentKey(string $key): bool
    {
        return preg_match('/comment/i', $key) === 1;
    }

    private function maskOrderParam(string $key, string $value, int $orderId): string
    {
        if (preg_match('/email/i', $key) === 1) {
            return 'anon+order_' . $orderId . '@example.invalid';
        }
        if (preg_match('/phone/i', $key) === 1) {
            return 'anon-order-' . sha1((string)$orderId);
        }
        if (preg_match('/(firstname|middlename|lastname|name|company)/i', $key) === 1) {
            return 'Deleted';
        }
        if (preg_match('/user_agent/i', $key) === 1) {
            return 'unknown';
        }
        if (preg_match('/(^|[_\-.])ip($|[_\-.])|ip_address|remote_addr/i', $key) === 1) {
            return '0.0.0.0';
        }
        if (preg_match('/(country|region|city|address|street|house|flat|zip)/i', $key) === 1) {
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

            $this->updateContactCore($contactId);
            $this->updateContactEmails($contactId);
            $this->updateContactData($contactId);
            $this->updateContactDataText($contactId);
            $this->updateContactAddresses($contactId);

            $this->markContactProcessed($contactId, 'processed');

            $processed[] = $contactId;
        }

        return array('processed' => $processed, 'skipped' => $skipped);
    }

    private function updateContactCore(int $contactId): void
    {
        $values = array(
            'name' => 'Deleted',
            'firstname' => 'Deleted',
            'middlename' => '',
            'lastname' => '',
            'title' => '',
            'company' => '',
            'company_contact_id' => 0,
            'jobtitle' => '',
            'about' => '',
            'sex' => null,
            'birth_day' => null,
            'birth_month' => null,
            'birth_year' => null,
            'photo' => 0,
        );

        $sets = array();
        $params = array(':contact_id' => $contactId);
        foreach ($values as $column => $value) {
            if (!$this->tableHasColumn('wa_contact', $column)) {
                continue;
            }
            $placeholder = ':' . $column;
            $sets[] = $this->quoteIdentifier($column) . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        if (!$sets) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE wa_contact
             SET ' . implode(', ', $sets) . '
             WHERE id = :contact_id'
        );
        $stmt->execute($params);
    }

    private function updateContactEmails(int $contactId): void
    {
        if (!$this->tableExists('wa_contact_emails')) {
            return;
        }

        $sets = array("email = CONCAT('anon+contact_', contact_id, '_', id, '@example.invalid')");
        if ($this->tableHasColumn('wa_contact_emails', 'status')) {
            $sets[] = "status = 'unavailable'";
        }

        $stmt = $this->pdo->prepare(
            'UPDATE wa_contact_emails
             SET ' . implode(', ', $sets) . '
             WHERE contact_id = :contact_id'
        );
        $stmt->execute(array(':contact_id' => $contactId));
    }

    private function updateContactData(int $contactId): void
    {
        if (!$this->tableExists('wa_contact_data')) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE wa_contact_data
             SET value = CASE
                 WHEN field = 'phone' OR field LIKE 'phone.%' OR field LIKE '%phone%' THEN :phone
                 WHEN field = 'email' OR field LIKE 'email.%' OR field LIKE '%email%' THEN :email
                 WHEN field LIKE '%name%' OR field LIKE '%company%' THEN 'Deleted'
                 ELSE ''
             END
             WHERE contact_id = :contact_id
               AND (
                   field = 'phone'
                   OR field LIKE 'phone.%'
                   OR field LIKE '%phone%'
                   OR field = 'email'
                   OR field LIKE 'email.%'
                   OR field LIKE '%email%'
                   OR field LIKE '%name%'
                   OR field LIKE '%company%'
                   OR field LIKE '%address%'
                   OR field LIKE '%street%'
                   OR field LIKE '%house%'
                   OR field LIKE '%flat%'
                   OR field LIKE '%zip%'
                   OR field LIKE '%city%'
                   OR field LIKE '%region%'
                   OR field LIKE '%country%'
                   OR field LIKE '%comment%'
               )"
        );
        $stmt->execute(array(
            ':phone' => 'anon-contact-' . sha1((string)$contactId),
            ':email' => 'anon+contact_' . $contactId . '@example.invalid',
            ':contact_id' => $contactId,
        ));
    }

    private function updateContactDataText(int $contactId): void
    {
        if (!$this->tableExists('wa_contact_data_text')) {
            return;
        }

        $sql = 'UPDATE wa_contact_data_text
                SET value = :value
                WHERE contact_id = :contact_id';
        if ($this->tableHasColumn('wa_contact_data_text', 'field')) {
            $sql .= " AND (
                field LIKE '%name%'
                OR field LIKE '%company%'
                OR field LIKE '%address%'
                OR field LIKE '%street%'
                OR field LIKE '%house%'
                OR field LIKE '%flat%'
                OR field LIKE '%zip%'
                OR field LIKE '%city%'
                OR field LIKE '%region%'
                OR field LIKE '%country%'
                OR field LIKE '%comment%'
            )";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array(
            ':value' => '',
            ':contact_id' => $contactId,
        ));
    }

    private function updateContactAddresses(int $contactId): void
    {
        if (!$this->tableExists('wa_contact_addresses')) {
            return;
        }

        $values = array(
            'name' => '',
            'firstname' => '',
            'middlename' => '',
            'lastname' => '',
            'company' => '',
            'street' => '',
            'house' => '',
            'flat' => '',
            'zip' => '',
            'city' => '',
            'region' => '',
            'country' => '',
            'address' => '',
            'phone' => 'anon-contact-' . sha1((string)$contactId),
            'comment' => '',
            'value' => '',
            'data' => '',
        );

        $sets = array();
        $params = array(':contact_id' => $contactId);
        foreach ($values as $column => $value) {
            if (!$this->tableHasColumn('wa_contact_addresses', $column)) {
                continue;
            }
            $placeholder = ':' . $column;
            $sets[] = $this->quoteIdentifier($column) . ' = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        if (!$sets) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE wa_contact_addresses
             SET ' . implode(', ', $sets) . '
             WHERE contact_id = :contact_id'
        );
        $stmt->execute($params);
    }

    private function getContactSkipReason(int $contactId, string $cutoff): ?string
    {
        if (!$this->tableExists('wa_contact')) {
            return 'wa_contact_missing';
        }

        $protectionReason = $this->getContactAccountProtectionReason($contactId);
        if ($protectionReason !== null) {
            return $protectionReason;
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

        if (!$this->stateTableReady) {
            try {
                $this->ensureStateTable();
            } catch (Throwable $error) {
                $this->log('error', 'Contact state table is unavailable', array(
                    'exception' => $error->getMessage(),
                ));
                return 'state_table_missing';
            }
        }

        if ($this->isContactAlreadyProcessed($contactId)) {
            return 'already_processed';
        }

        return null;
    }

    private function getContactAccountProtectionReason(int $contactId): ?string
    {
        $columns = array('id');
        foreach (array('is_staff', 'is_user', 'login') as $column) {
            if ($this->tableHasColumn('wa_contact', $column)) {
                $columns[] = $column;
            }
        }

        $select = array();
        foreach ($columns as $column) {
            $select[] = $this->quoteIdentifier($column);
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', $select) . '
             FROM wa_contact
             WHERE id = :contact_id
             LIMIT 1'
        );
        $stmt->execute(array(':contact_id' => $contactId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'contact_missing';
        }

        if (isset($row['is_staff']) && (int)$row['is_staff'] > 0) {
            return 'staff_contact';
        }
        if (isset($row['is_user']) && (int)$row['is_user'] > 0) {
            return 'user_contact';
        }
        if (isset($row['login']) && trim((string)$row['login']) !== '') {
            return 'has_login';
        }

        return null;
    }

    private function isContactAlreadyProcessed(int $contactId): bool
    {
        if (!$this->stateTableReady) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM depersonalizer_state
             WHERE entity_type = :entity_type
               AND entity_id = :entity_id
             LIMIT 1'
        );
        $stmt->execute(array(
            ':entity_type' => 'contact',
            ':entity_id' => $contactId,
        ));

        return (bool)$stmt->fetchColumn();
    }

    private function markContactProcessed(int $contactId, string $note): void
    {
        if (!$this->stateTableReady) {
            $this->ensureStateTable();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO depersonalizer_state
                (entity_type, entity_id, processed_at, run_id, note, payload)
             VALUES
                (:entity_type, :entity_id, NOW(), :run_id, :note, :payload)
             ON DUPLICATE KEY UPDATE
                processed_at = VALUES(processed_at),
                run_id = VALUES(run_id),
                note = VALUES(note),
                payload = VALUES(payload)'
        );
        $stmt->execute(array(
            ':entity_type' => 'contact',
            ':entity_id' => $contactId,
            ':run_id' => $this->runId,
            ':note' => $note,
            ':payload' => json_encode(array('source' => 'standalone'), JSON_UNESCAPED_SLASHES),
        ));
    }

    private function ensureStateTable(): void
    {
        if ($this->stateTableReady && $this->tableExists(self::STATE_TABLE)) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS depersonalizer_state (
                entity_type VARCHAR(32) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                processed_at DATETIME NOT NULL,
                run_id VARCHAR(64) NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                payload TEXT DEFAULT NULL,
                PRIMARY KEY (entity_type, entity_id),
                KEY run_id (run_id),
                KEY processed_at (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $this->tableExistsCache[self::STATE_TABLE] = true;
        $this->stateTableReady = true;
    }

    private function cutoff(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
    }

    private function countOrdersOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':processed_key' => self::ORDER_PROCESSED_KEY,
            ':processed_value' => '1',
        ));
        return (int)$stmt->fetchColumn();
    }

    private function countOrdersUpToCursor(string $cutoff, int $cursor): int
    {
        if ($cursor <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.id <= :cursor
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )'
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':processed_key', self::ORDER_PROCESSED_KEY, PDO::PARAM_STR);
        $stmt->bindValue(':processed_value', '1', PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    private function hasOrdersAfterCursor(string $cutoff, int $cursor): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.id > :cursor
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )
             LIMIT 1'
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':processed_key', self::ORDER_PROCESSED_KEY, PDO::PARAM_STR);
        $stmt->bindValue(':processed_value', '1', PDO::PARAM_STR);
        $stmt->execute();

        return (bool)$stmt->fetchColumn();
    }

    private function countOrdersAfterCursor(string $cutoff, int $cursor): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.id > :cursor
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )'
        );
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':processed_key', self::ORDER_PROCESSED_KEY, PDO::PARAM_STR);
        $stmt->bindValue(':processed_value', '1', PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
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
        $batchesDir = $this->logDir . DIRECTORY_SEPARATOR . 'batches';
        $this->ensureDirectory($batchesDir);

        $dateDir = $batchesDir . DIRECTORY_SEPARATOR . date('Y-m-d');
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
            if (!mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException('Unable to create directory: ' . $path);
            }
        }

        $htaccess = $path . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\nDeny from all\n");
        }

        $webConfig = $path . DIRECTORY_SEPARATOR . 'web.config';
        if (!is_file($webConfig)) {
            file_put_contents(
                $webConfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
                "<configuration>\n" .
                "  <system.webServer>\n" .
                "    <security>\n" .
                "      <authorization>\n" .
                "        <remove users=\"*\" roles=\"\" verbs=\"\" />\n" .
                "        <add accessType=\"Deny\" users=\"*\" />\n" .
                "      </authorization>\n" .
                "    </security>\n" .
                "  </system.webServer>\n" .
                "</configuration>\n"
            );
        }

        $index = $path . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) {
            file_put_contents($index, '');
        }
    }
}
