<?php
declare(strict_types=1);

final class StandaloneDepersonalizer
{
    private const ORDER_PROCESSED_KEY = '_depersonalizer_ext_processed';
    private const ORDER_PROCESSED_AT_KEY = '_depersonalizer_ext_processed_at';
    private const ORDER_LOG_PROCESSED_KEY = '_depersonalizer_order_log_processed';
    private const ORDER_LOG_PROCESSED_AT_KEY = '_depersonalizer_order_log_processed_at';
    private const PLACEHOLDER_NAME = 'Обезличен';
    private const PLACEHOLDER_HISTORY = 'Запись истории заказа обезличена';
    private const PLACEHOLDER_EMAIL_DOMAIN = 'obezlicheno.invalid';

    private const STATE_TABLE = 'depersonalizer_state';

    /** @var array<int, string> */
    private $damagedServiceParamKeys = array('payment_name', 'shipping_name');

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

    /** @var array<string, array<string, string>> */
    private $tableColumnTypesCache = array();

    /** @var string|null */
    private $orderDisplayColumnCache = null;

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
                'Автоматически создается только принадлежащая этому инструменту таблица depersonalizer_state.',
                'Строки заказов и контактов обновляются на месте; ID и связи сохраняются.',
                'Заказы помечаются в shop_order_params, контакты - в depersonalizer_state.',
                'wa_contact_params необязательна и не требуется для загрузки страницы или состояния контактов.',
                'Строки адресов этот standalone-инструмент никогда не удаляет.',
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
        $oldOrdersTotal = $this->countAllOrdersOlderThan($cutoff);

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
            'old_orders_total' => $oldOrdersTotal,
            'total_orders' => $totalOrders,
            'candidate_keys' => $keys,
            'contact_catchup' => $this->previewContactCatchup($cutoff),
            'order_history' => $this->previewOrderHistory($cutoff),
            'placeholder_normalization' => $this->previewPlaceholderNormalization($cutoff),
            'damaged_service_fields' => $this->countDamagedServiceOrderParams($cutoff),
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
        $this->runId = $this->resolveRunId($options['run_id'] ?? '');

        $days = $this->normalizeDays((int)($options['days'] ?? 365));
        $limit = $this->normalizeLimit((int)($options['limit'] ?? 200));
        $cursor = max(0, (int)($options['cursor'] ?? 0));

        $keepGeo = !empty($options['keep_geo']);
        $wipeComments = !empty($options['wipe_comments']);
        $anonymizeContacts = !empty($options['anonymize_contacts']);
        $contactCatchupOnly = !empty($options['contact_catchup_only']);
        $contactOrderScope = $this->normalizeContactOrderScope($options['contact_order_scope'] ?? 'processed');
        $contactCursor = max(0, (int)($options['contact_cursor'] ?? 0));
        $anonymizeOrderHistory = !empty($options['anonymize_order_history']);
        $dryRun = !empty($options['dry_run']);
        $historyCursor = max(0, (int)($options['history_cursor'] ?? 0));

        if (!$dryRun) {
            $backupConfirmed = !empty($options['backup_confirmed']);
            $confirmationPhrase = trim((string)($options['confirmation_phrase'] ?? ''));
            if (!$backupConfirmed || $confirmationPhrase !== 'ANONYMIZE') {
                throw new RuntimeException('Для реального обезличивания нужна отметка о резервной копии и точная фраза ANONYMIZE.');
            }
        }

        if ($contactCatchupOnly || ($anonymizeContacts && $contactOrderScope === 'processed')) {
            return $this->runContactCatchupBatch($days, $limit, $contactCursor, $dryRun, $anonymizeOrderHistory, $historyCursor, $contactOrderScope);
        }

        if ($anonymizeContacts && !$dryRun && !$this->stateTableReady) {
            $this->ensureStateTable();
        }

        $includeKeys = $this->normalizeIncludeKeys($options['include_keys'] ?? array());
        $includeMap = array_fill_keys($includeKeys, true);

        $cutoff = $this->cutoff($days);
        $orderTotal = $this->countOrdersOlderThan($cutoff);

        $orders = $this->fetchOrdersBatch($cutoff, $cursor, $limit);
        $processedOrders = array();
        $processedOrderNumbers = array();
        $skippedOrders = array();
        $processedContacts = array();
        $skippedContacts = array();
        $contactResult = $this->emptyContactScopeResult($contactCursor);
        $historyResult = $this->emptyOrderHistoryResult($historyCursor);
        $normalizationResult = $this->emptyNormalizationResult();

        if (!$dryRun) {
            $this->pdo->beginTransaction();
        }

        try {
            $normalizationResult = $this->runPlaceholderNormalization($cutoff, true, true, $anonymizeOrderHistory, $dryRun);

            if ($anonymizeOrderHistory) {
                $historyResult = $this->runOrderHistoryBatch($cutoff, $historyCursor, $limit, $dryRun);
            }

            if ($anonymizeContacts) {
                $contactResult = $this->runContactScopeBatch($cutoff, $contactOrderScope, $contactCursor, $limit, $dryRun);
                $processedContacts = $contactResult['processed'];
                $skippedContacts = $contactResult['skipped'];
            }

            foreach ($orders as $order) {
                $orderId = (int)$order['id'];

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
                $processedOrderNumbers[] = $this->formatOrderIdentifier($order);
            }

            if (!$dryRun) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->log('error', 'Ошибка пакета', array(
                'cursor' => $cursor,
                'exception' => $error->getMessage(),
            ));
            throw $error;
        }

        $nextCursor = $orders ? (int)$orders[count($orders) - 1]['id'] : $cursor;
        $ordersDone = $orders ? !$this->hasOrdersAfterCursor($cutoff, $nextCursor) : true;
        $orderProgress = $orders ? max(0, $orderTotal - $this->countOrdersAfterCursor($cutoff, $nextCursor)) : $this->countOrdersUpToCursor($cutoff, $cursor);
        $contactsDone = empty($contactResult['enabled']) || !empty($contactResult['done']);
        $done = $ordersDone && $contactsDone && (empty($historyResult['enabled']) || !empty($historyResult['done']));
        $total = $orderTotal + (int)$historyResult['total'] + (int)$contactResult['total'];
        $progress = $orderProgress + (int)$historyResult['progress'] + (int)$contactResult['progress'];
        $processedOrderSummary = $this->compactOrderIdentifierRanges($processedOrderNumbers);

        $batchPayload = array(
            'run_id' => $this->runId,
            'timestamp' => date('c'),
            'dry_run' => $dryRun,
            'options' => array(
                'days' => $days,
                'limit' => $limit,
                'cursor' => $cursor,
                'history_cursor' => $historyCursor,
                'contact_cursor' => $contactCursor,
                'keep_geo' => $keepGeo,
                'wipe_comments' => $wipeComments,
                'anonymize_contacts' => $anonymizeContacts,
                'contact_catchup_only' => $contactCatchupOnly,
                'contact_order_scope' => $contactOrderScope,
                'anonymize_order_history' => $anonymizeOrderHistory,
                'include_keys' => $includeKeys,
            ),
            'cutoff' => $cutoff,
            'processed_orders' => $processedOrders,
            'processed_order_numbers' => $processedOrderNumbers,
            'processed_order_numbers_summary' => $processedOrderSummary,
            'skipped_orders' => $skippedOrders,
            'processed_contacts' => $processedContacts,
            'skipped_contacts' => $skippedContacts,
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'skipped_history_rows' => (int)$historyResult['skipped_rows'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'normalization_breakdown' => $normalizationResult['breakdown'],
            'selected_include_keys' => $includeKeys,
        );

        $batchLog = $this->writeBatchLog($batchPayload);

        $this->log('info', 'Пакет завершен', array(
            'cursor_from' => $cursor,
            'cursor_to' => $nextCursor,
            'dry_run' => $dryRun,
            'processed_orders' => count($processedOrders),
            'processed_contacts' => count($processedContacts),
            'skipped_orders' => count($skippedOrders),
            'skipped_contacts' => count($skippedContacts),
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'processed_order_numbers_summary' => $processedOrderSummary,
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
            'processed_order_numbers' => $processedOrderNumbers,
            'processed_order_numbers_summary' => $processedOrderSummary,
            'skipped_orders' => $skippedOrders,
            'processed_contacts' => $processedContacts,
            'skipped_contacts' => $skippedContacts,
            'contact_cursor' => (int)$contactResult['cursor'],
            'contact_done' => !empty($contactResult['done']),
            'contact_total' => (int)$contactResult['total'],
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'skipped_history_rows' => (int)$historyResult['skipped_rows'],
            'history_cursor' => (int)$historyResult['cursor'],
            'history_done' => !empty($historyResult['done']),
            'history_total' => (int)$historyResult['total'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'normalization_breakdown' => $normalizationResult['breakdown'],
            'dry_run' => $dryRun,
            'run_id' => $this->runId,
            'batch_log' => $batchLog,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyContactScopeResult(int $cursor): array
    {
        return array(
            'enabled' => false,
            'cursor' => $cursor,
            'done' => true,
            'total' => 0,
            'progress' => 0,
            'processed' => array(),
            'skipped' => array(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runContactScopeBatch(string $cutoff, string $scope, int $cursor, int $limit, bool $dryRun): array
    {
        $result = $this->emptyContactScopeResult($cursor);
        $result['enabled'] = true;

        if (!$this->tableExists('wa_contact')) {
            $result['done'] = true;
            $result['skipped'] = array('wa_contact' => 'wa_contact_missing');
            return $result;
        }

        $total = $this->countContactCatchupCandidates($cutoff, 0, $scope);
        $contactIds = $this->fetchContactCatchupBatch($cutoff, $cursor, $limit, $scope);
        $contactResult = $dryRun
            ? $this->simulateContacts($contactIds, $cutoff)
            : $this->processContacts($contactIds, $cutoff);

        $nextCursor = $contactIds ? max($contactIds) : $cursor;
        $done = $contactIds ? !$this->hasContactCatchupAfterCursor($cutoff, $nextCursor, $scope) : true;
        $remainingAfterCursor = $contactIds ? $this->countContactCatchupCandidates($cutoff, $nextCursor, $scope) : 0;

        $result['cursor'] = $nextCursor;
        $result['done'] = $done;
        $result['total'] = $total;
        $result['progress'] = $done ? $total : max(0, $total - $remainingAfterCursor);
        $result['processed'] = $contactResult['processed'];
        $result['skipped'] = $contactResult['skipped'];

        return $result;
    }

    /**
     * Process contacts linked to old orders without touching order rows or order params.
     *
     * @return array<string, mixed>
     */
    private function runContactCatchupBatch(int $days, int $limit, int $cursor, bool $dryRun, bool $anonymizeOrderHistory, int $historyCursor, string $contactOrderScope): array
    {
        if (!$this->tableExists('wa_contact')) {
            throw new RuntimeException('Для догоняющей обработки контактов нужна таблица wa_contact.');
        }

        if (!$dryRun) {
            $this->ensureStateTable();
        } else {
            $this->refreshStateTableReady();
        }

        $cutoff = $this->cutoff($days);
        $historyResult = $this->emptyOrderHistoryResult($historyCursor);
        $normalizationResult = $this->emptyNormalizationResult();
        $contactResult = $this->emptyContactScopeResult($cursor);

        if (!$dryRun) {
            $this->pdo->beginTransaction();
        }

        try {
            $normalizationResult = $this->runPlaceholderNormalization($cutoff, false, true, $anonymizeOrderHistory, $dryRun);

            if ($anonymizeOrderHistory) {
                $historyResult = $this->runOrderHistoryBatch($cutoff, $historyCursor, $limit, $dryRun);
            }

            $contactResult = $this->runContactScopeBatch($cutoff, $contactOrderScope, $cursor, $limit, $dryRun);

            if (!$dryRun) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->log('error', 'Ошибка догоняющей обработки контактов', array(
                'cursor' => $cursor,
                'exception' => $error->getMessage(),
            ));
            throw $error;
        }

        $done = !empty($contactResult['done']) && (empty($historyResult['enabled']) || !empty($historyResult['done']));
        $combinedTotal = (int)$contactResult['total'] + (int)$historyResult['total'];
        $combinedProgress = (int)$contactResult['progress'] + (int)$historyResult['progress'];

        $batchPayload = array(
            'run_id' => $this->runId,
            'timestamp' => date('c'),
            'dry_run' => $dryRun,
            'options' => array(
                'days' => $days,
                'limit' => $limit,
                'cursor' => $cursor,
                'history_cursor' => $historyCursor,
                'contact_cursor' => $cursor,
                'contact_catchup_only' => true,
                'contact_order_scope' => $contactOrderScope,
                'anonymize_order_history' => $anonymizeOrderHistory,
            ),
            'cutoff' => $cutoff,
            'processed_orders' => array(),
            'processed_order_numbers' => array(),
            'processed_order_numbers_summary' => '',
            'skipped_orders' => array(),
            'processed_contacts' => $contactResult['processed'],
            'skipped_contacts' => $contactResult['skipped'],
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'skipped_history_rows' => (int)$historyResult['skipped_rows'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'normalization_breakdown' => $normalizationResult['breakdown'],
            'selected_include_keys' => array(),
        );

        $batchLog = $this->writeBatchLog($batchPayload);

        $this->log('info', 'Пакет догоняющей обработки контактов завершен', array(
            'cursor_from' => $cursor,
            'cursor_to' => (int)$contactResult['cursor'],
            'dry_run' => $dryRun,
            'processed_contacts' => count($contactResult['processed']),
            'skipped_contacts' => count($contactResult['skipped']),
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'contact_order_scope' => $contactOrderScope,
            'run_id' => $this->runId,
            'batch_log' => $batchLog,
        ));

        return array(
            'cutoff' => $cutoff,
            'cursor' => 0,
            'done' => $done,
            'batch_count' => count($contactResult['processed']) + count($contactResult['skipped']),
            'progress' => $combinedProgress,
            'total' => $combinedTotal,
            'processed_orders' => array(),
            'processed_order_numbers' => array(),
            'processed_order_numbers_summary' => '',
            'skipped_orders' => array(),
            'processed_contacts' => $contactResult['processed'],
            'skipped_contacts' => $contactResult['skipped'],
            'contact_cursor' => (int)$contactResult['cursor'],
            'contact_done' => !empty($contactResult['done']),
            'contact_total' => (int)$contactResult['total'],
            'processed_history_rows' => (int)$historyResult['processed_rows'],
            'skipped_history_rows' => (int)$historyResult['skipped_rows'],
            'history_cursor' => (int)$historyResult['cursor'],
            'history_done' => !empty($historyResult['done']),
            'history_total' => (int)$historyResult['total'],
            'normalized_placeholders' => (int)$normalizationResult['total'],
            'normalization_breakdown' => $normalizationResult['breakdown'],
            'dry_run' => $dryRun,
            'run_id' => $this->runId,
            'batch_log' => $batchLog,
        );
    }

    /**
     * @param string $cutoff
     * @param int $cursor
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrdersBatch(string $cutoff, int $cursor, int $limit): array
    {
        $displayColumn = $this->getOrderDisplayColumn();
        $select = 'id, contact_id';
        if ($displayColumn !== '') {
            $select .= ', ' . $this->quoteIdentifier($displayColumn) . ' AS display_number';
        }

        $sql = 'SELECT ' . $select . '
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
            $row['display_number'] = trim((string)($row['display_number'] ?? ''));
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
     * @return array<string, mixed>
     */
    private function previewContactCatchup(string $cutoff): array
    {
        $this->refreshStateTableReady();

        $alreadyProcessed = $this->countAlreadyProcessedOldContacts($cutoff);
        $withNewerOrders = $this->countOldContactsWithNewerOrders($cutoff);
        $emptyScopes = array(
            'processed' => $this->emptyContactScopePreview(),
            'unprocessed' => $this->emptyContactScopePreview(),
            'all' => $this->emptyContactScopePreview(),
        );

        if (!$this->tableExists('wa_contact')) {
            return array(
                'available' => false,
                'candidate_contacts' => 0,
                'eligible_contacts' => 0,
                'already_processed_contacts' => $alreadyProcessed,
                'contacts_with_newer_orders' => $withNewerOrders,
                'protected_contacts' => 0,
                'scopes' => $emptyScopes,
                'note' => 'Таблица wa_contact отсутствует, обработка контактов недоступна.',
            );
        }

        $scopeStats = array();
        foreach (array('processed', 'unprocessed', 'all') as $scope) {
            $scopeStats[$scope] = $this->previewContactScope($cutoff, $scope);
        }

        $allScope = $scopeStats['all'];

        return array(
            'available' => true,
            'candidate_contacts' => $allScope['candidate_contacts'],
            'eligible_contacts' => $allScope['eligible_contacts'],
            'already_processed_contacts' => $alreadyProcessed,
            'contacts_with_newer_orders' => $withNewerOrders,
            'protected_contacts' => $allScope['protected_contacts'],
            'scopes' => $scopeStats,
            'note' => '',
        );
    }

    /**
     * @return array<string, int>
     */
    private function emptyContactScopePreview(): array
    {
        return array(
            'candidate_contacts' => 0,
            'eligible_contacts' => 0,
            'protected_contacts' => 0,
        );
    }

    /**
     * @return array<string, int>
     */
    private function previewContactScope(string $cutoff, string $scope): array
    {
        $candidateContacts = $this->countContactCatchupCandidates($cutoff, 0, $scope);
        $contactIds = $this->fetchContactCatchupBatch($cutoff, 0, 0, $scope);
        $eligibleContacts = 0;
        $protectedContacts = 0;

        foreach ($contactIds as $contactId) {
            if ($this->getContactAccountProtectionReason($contactId) === null) {
                $eligibleContacts++;
            } else {
                $protectedContacts++;
            }
        }

        return array(
            'candidate_contacts' => $candidateContacts,
            'eligible_contacts' => $eligibleContacts,
            'protected_contacts' => $protectedContacts,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function previewOrderHistory(string $cutoff): array
    {
        if (!$this->tableExists('shop_order_log')) {
            return array(
                'available' => false,
                'exists' => false,
                'table' => 'shop_order_log',
                'columns' => array(),
                'all_columns' => array(),
                'safe_text_columns' => array(),
                'old_order_log_rows' => 0,
                'rows_with_safe_text' => 0,
                'rows_to_process' => 0,
                'already_marked_orders' => 0,
                'reason' => 'table_missing',
                'note' => 'Таблица shop_order_log отсутствует.',
            );
        }

        $allColumns = array_keys($this->tableColumnTypes('shop_order_log'));
        $columns = $this->getOrderHistoryTextColumns();
        $oldLogRows = $this->countAllOrderHistoryRows($cutoff);
        $alreadyMarked = $this->countOrderHistoryMarkedOrders($cutoff);
        if (!$columns) {
            return array(
                'available' => false,
                'exists' => true,
                'table' => 'shop_order_log',
                'columns' => array(),
                'all_columns' => $allColumns,
                'safe_text_columns' => array(),
                'old_order_log_rows' => $oldLogRows,
                'rows_with_safe_text' => 0,
                'rows_to_process' => 0,
                'already_marked_orders' => $alreadyMarked,
                'reason' => 'no_safe_text_columns',
                'note' => 'В shop_order_log не найдены безопасные текстовые колонки для обработки.',
            );
        }

        $rowsWithSafeText = $this->countOrderHistoryRowsWithSafeText($cutoff, $columns);
        $rowsToProcess = $this->countOrderHistoryRows($cutoff, 0, $columns);
        $reason = '';
        $note = '';
        if ($oldLogRows === 0) {
            $reason = 'no_old_order_log_rows';
            $note = 'Для старых заказов не найдены строки истории.';
        } elseif ($rowsWithSafeText === 0) {
            $reason = 'safe_columns_empty';
            $note = 'Безопасные текстовые колонки истории пусты. Видимая история может формироваться из структурированных params/data, которые этот безопасный режим пока не меняет.';
        } elseif ($rowsToProcess === 0) {
            $reason = 'all_rows_already_marked';
            $note = 'Строки истории с безопасным текстом уже помечены обработанными. Если в интерфейсе остались данные, вероятно, они формируются из структурированных params/data и требуют отдельного обработчика.';
        }

        return array(
            'available' => true,
            'exists' => true,
            'table' => 'shop_order_log',
            'columns' => $columns,
            'all_columns' => $allColumns,
            'safe_text_columns' => $columns,
            'old_order_log_rows' => $oldLogRows,
            'rows_with_safe_text' => $rowsWithSafeText,
            'rows_to_process' => $rowsToProcess,
            'already_marked_orders' => $alreadyMarked,
            'reason' => $reason,
            'note' => $note,
            'preserves_actor_attribution' => true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPlaceholderNormalization(string $cutoff): array
    {
        return $this->runPlaceholderNormalization($cutoff, true, true, true, true);
    }

    /**
     * Save snapshot geo_* params based on existing geo-like order keys.
     *
     * @param int $orderId
     * @param array<string, string> $params
     */
    private function preserveGeoSnapshot(int $orderId, array $params): void
    {
        $sources = array(
            'city' => array('shipping_address.city', 'billing_address.city', 'shipping_city', 'billing_city', 'city'),
            'region' => array('shipping_address.region', 'billing_address.region', 'shipping_region', 'billing_region', 'region'),
            'country' => array('shipping_address.country', 'billing_address.country', 'shipping_country', 'billing_country', 'country'),
            'lat' => array('shipping_address.lat', 'billing_address.lat', 'shipping_lat', 'billing_lat', 'lat'),
            'lng' => array('shipping_address.lng', 'billing_address.lng', 'shipping_lng', 'billing_lng', 'lng'),
        );

        foreach ($sources as $geoKey => $sourceKeys) {
            $targetKey = 'geo_' . $geoKey;
            if (array_key_exists($targetKey, $params) && trim((string)$params[$targetKey]) !== '') {
                continue;
            }

            foreach ($sourceKeys as $sourceKey) {
                if (array_key_exists($sourceKey, $params) && trim((string)$params[$sourceKey]) !== '') {
                    $this->setOrderParam($orderId, $targetKey, (string)$params[$sourceKey]);
                    break;
                }
            }
        }
    }

    private function isPiiKey(string $key): bool
    {
        if ($this->isDamagedServiceParamKey($key)) {
            return false;
        }

        if ($this->isTechnicalParamKey($key)) {
            return false;
        }

        if ($this->isExcludedCarrierPluginKey($key)) {
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

    private function isDamagedServiceParamKey(string $key): bool
    {
        return in_array(strtolower($key), $this->damagedServiceParamKeys, true);
    }

    private function isTechnicalParamKey(string $key): bool
    {
        return preg_match('/(^|_)(id|code|plugin|module|rate|method|currency|tax|total|price|amount|sku|quantity|status|state|workflow)$/i', $key) === 1;
    }

    private function isExcludedCarrierPluginKey(string $key): bool
    {
        $lowerKey = strtolower($key);
        foreach (array('sdekint_plugin.', 'cdek.', 'cdek_', 'sdek.', 'sdek_') as $prefix) {
            if (strpos($lowerKey, $prefix) === 0) {
                return true;
            }
        }

        return false;
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
            return 'obezlicheno+order_' . $orderId . '@' . self::PLACEHOLDER_EMAIL_DOMAIN;
        }
        if (preg_match('/phone/i', $key) === 1) {
            return 'obezlicheno-order-' . sha1((string)$orderId);
        }
        if (preg_match('/(firstname|middlename|lastname|name|company)/i', $key) === 1) {
            return self::PLACEHOLDER_NAME;
        }
        if (preg_match('/user_agent/i', $key) === 1) {
            return 'обезличено';
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
            $reason = $this->getContactSkipReason($contactId, $cutoff, false);
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
            $reason = $this->getContactSkipReason($contactId, $cutoff, true);
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
            'name' => self::PLACEHOLDER_NAME,
            'firstname' => self::PLACEHOLDER_NAME,
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

        $sets = array("email = CONCAT('obezlicheno+contact_', contact_id, '_', id, '@" . self::PLACEHOLDER_EMAIL_DOMAIN . "')");
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
                 WHEN field LIKE '%name%' OR field LIKE '%company%' THEN :name
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
            ':phone' => 'obezlicheno-contact-' . sha1((string)$contactId),
            ':email' => 'obezlicheno+contact_' . $contactId . '@' . self::PLACEHOLDER_EMAIL_DOMAIN,
            ':name' => self::PLACEHOLDER_NAME,
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
            'phone' => 'obezlicheno-contact-' . sha1((string)$contactId),
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

    private function getContactSkipReason(int $contactId, string $cutoff, bool $allowStateSetup = true): ?string
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

        $this->refreshStateTableReady();
        if (!$this->stateTableReady && $allowStateSetup) {
            try {
                $this->ensureStateTable();
            } catch (Throwable $error) {
                $this->log('error', 'Таблица состояния контактов недоступна', array(
                    'exception' => $error->getMessage(),
                ));
                return 'state_table_missing';
            }
        }

        if ($this->stateTableReady && $this->isContactAlreadyProcessed($contactId)) {
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
                run_id VARCHAR(80) NOT NULL,
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

    /**
     * @return array<string, mixed>
     */
    private function emptyOrderHistoryResult(int $cursor): array
    {
        return array(
            'enabled' => false,
            'available' => false,
            'cursor' => $cursor,
            'done' => true,
            'total' => 0,
            'progress' => 0,
            'processed_rows' => 0,
            'skipped_rows' => 0,
            'columns' => array(),
            'note' => '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyNormalizationResult(): array
    {
        return array(
            'total' => 0,
            'breakdown' => array(
                'order_params' => 0,
                'contacts' => 0,
                'order_history' => 0,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runOrderHistoryBatch(string $cutoff, int $cursor, int $limit, bool $dryRun): array
    {
        $result = $this->emptyOrderHistoryResult($cursor);
        $result['enabled'] = true;

        if (!$this->tableExists('shop_order_log')) {
            $result['note'] = 'Таблица shop_order_log отсутствует.';
            return $result;
        }

        $columns = $this->getOrderHistoryTextColumns();
        $result['columns'] = $columns;
        if (!$columns) {
            $result['note'] = 'В shop_order_log не найдены безопасные текстовые колонки для обработки.';
            return $result;
        }

        $result['available'] = true;
        $total = $this->countOrderHistoryRows($cutoff, 0, $columns);
        $orderIds = $this->fetchOrderHistoryOrderIds($cutoff, $cursor, $limit, $columns);
        $processedRows = $orderIds ? $this->countOrderHistoryRowsForOrderIds($orderIds, $columns) : 0;

        if ($orderIds && !$dryRun) {
            $this->sanitizeOrderHistoryRows($orderIds, $columns);
            foreach ($orderIds as $orderId) {
                $this->setOrderParam($orderId, self::ORDER_LOG_PROCESSED_KEY, '1');
                $this->setOrderParam($orderId, self::ORDER_LOG_PROCESSED_AT_KEY, date('Y-m-d H:i:s'));
            }
        }

        $nextCursor = $orderIds ? max($orderIds) : $cursor;
        $done = $orderIds ? !$this->hasOrderHistoryAfterCursor($cutoff, $nextCursor, $columns) : true;
        $remainingAfterCursor = $orderIds ? $this->countOrderHistoryRows($cutoff, $nextCursor, $columns) : 0;

        $result['cursor'] = $nextCursor;
        $result['done'] = $done;
        $result['total'] = $total;
        $result['progress'] = $done ? $total : max(0, $total - $remainingAfterCursor);
        $result['processed_rows'] = $processedRows;

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function getOrderHistoryTextColumns(): array
    {
        if (!$this->tableExists('shop_order_log') || !$this->tableHasColumn('shop_order_log', 'order_id')) {
            return array();
        }

        $columns = array();
        foreach ($this->tableColumnTypes('shop_order_log') as $column => $type) {
            if ($this->isOrderHistoryTextColumn($column, $type)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function isOrderHistoryTextColumn(string $column, string $type): bool
    {
        $column = strtolower($column);
        $type = strtolower($type);

        $excluded = array(
            'id',
            'order_id',
            'contact_id',
            'action_id',
            'state_id',
            'before_state_id',
            'after_state_id',
            'datetime',
            'create_datetime',
            'update_datetime',
            'date',
            'workflow',
            'status',
            'app_id',
            'plugin',
            'params_hash',
        );
        if (in_array($column, $excluded, true)) {
            return false;
        }

        $allowed = array(
            'text',
            'comment',
            'description',
            'details',
            'message',
            'contact_name',
            'value',
        );
        if (!in_array($column, $allowed, true)) {
            return false;
        }

        return $this->isTextColumnType($type);
    }

    private function isTextColumnType(string $type): bool
    {
        return preg_match('/char|text|varchar|mediumtext|longtext|tinytext/i', $type) === 1;
    }

    private function countAllOrderHistoryRows(string $cutoff): int
    {
        if (!$this->tableExists('shop_order_log')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order_log l
             INNER JOIN shop_order o ON o.id = l.order_id
             WHERE o.create_datetime < :cutoff'
        );
        $stmt->execute(array(':cutoff' => $cutoff));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<int, string> $columns
     */
    private function countOrderHistoryRowsWithSafeText(string $cutoff, array $columns): int
    {
        if (!$columns) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order_log l
             INNER JOIN shop_order o ON o.id = l.order_id
             WHERE o.create_datetime < :cutoff
               AND (' . $this->orderHistoryTextCondition('l', $columns) . ')'
        );
        $stmt->execute(array(':cutoff' => $cutoff));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<int, string> $columns
     */
    private function countOrderHistoryRows(string $cutoff, int $cursor, array $columns): int
    {
        if (!$columns) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order_log l
             INNER JOIN shop_order o ON o.id = l.order_id
             WHERE o.create_datetime < :cutoff
               AND o.id > :cursor
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )
               AND (' . $this->orderHistoryTextCondition('l', $columns) . ')'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':cursor' => $cursor,
            ':processed_key' => self::ORDER_LOG_PROCESSED_KEY,
            ':processed_value' => '1',
        ));

        return (int)$stmt->fetchColumn();
    }

    private function countOrderHistoryMarkedOrders(string $cutoff): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT o.id)
             FROM shop_order o
             INNER JOIN shop_order_params done
                ON done.order_id = o.id
               AND done.name = :processed_key
               AND done.value = :processed_value
             WHERE o.create_datetime < :cutoff'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':processed_key' => self::ORDER_LOG_PROCESSED_KEY,
            ':processed_value' => '1',
        ));

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, int>
     */
    private function fetchOrderHistoryOrderIds(string $cutoff, int $cursor, int $limit, array $columns): array
    {
        if (!$columns) {
            return array();
        }

        $sql = 'SELECT DISTINCT o.id
                FROM shop_order o
                INNER JOIN shop_order_log l ON l.order_id = o.id
                WHERE o.create_datetime < :cutoff
                  AND o.id > :cursor
                  AND NOT EXISTS (
                      SELECT 1
                      FROM shop_order_params done
                      WHERE done.order_id = o.id
                        AND done.name = :processed_key
                        AND done.value = :processed_value
                  )
                  AND (' . $this->orderHistoryTextCondition('l', $columns) . ')
                ORDER BY o.id ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':processed_key', self::ORDER_LOG_PROCESSED_KEY, PDO::PARAM_STR);
        $stmt->bindValue(':processed_value', '1', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $ids = array();
        while (($orderId = $stmt->fetchColumn()) !== false) {
            $ids[] = (int)$orderId;
        }

        return $ids;
    }

    /**
     * @param array<int, int> $orderIds
     * @param array<int, string> $columns
     */
    private function countOrderHistoryRowsForOrderIds(array $orderIds, array $columns): int
    {
        if (!$orderIds || !$columns) {
            return 0;
        }

        $stmt = $this->pdo->query(
            'SELECT COUNT(*)
             FROM shop_order_log l
             WHERE l.order_id IN (' . $this->intList($orderIds) . ')
               AND (' . $this->orderHistoryTextCondition('l', $columns) . ')'
        );

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<int, int> $orderIds
     * @param array<int, string> $columns
     */
    private function sanitizeOrderHistoryRows(array $orderIds, array $columns): void
    {
        if (!$orderIds || !$columns) {
            return;
        }

        $orderList = $this->intList($orderIds);
        foreach ($columns as $column) {
            $safeColumn = $this->quoteIdentifier($column);
            $stmt = $this->pdo->prepare(
                'UPDATE shop_order_log
                 SET ' . $safeColumn . ' = :replacement
                 WHERE order_id IN (' . $orderList . ')
                   AND ' . $safeColumn . ' IS NOT NULL
                   AND ' . $safeColumn . " <> ''
                   AND " . $safeColumn . ' <> :replacement_check'
            );
            $stmt->execute(array(
                ':replacement' => self::PLACEHOLDER_HISTORY,
                ':replacement_check' => self::PLACEHOLDER_HISTORY,
            ));
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function hasOrderHistoryAfterCursor(string $cutoff, int $cursor, array $columns): bool
    {
        if (!$columns) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM shop_order o
             INNER JOIN shop_order_log l ON l.order_id = o.id
             WHERE o.create_datetime < :cutoff
               AND o.id > :cursor
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order_params done
                   WHERE done.order_id = o.id
                     AND done.name = :processed_key
                     AND done.value = :processed_value
               )
               AND (' . $this->orderHistoryTextCondition('l', $columns) . ')
             LIMIT 1'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':cursor' => $cursor,
            ':processed_key' => self::ORDER_LOG_PROCESSED_KEY,
            ':processed_value' => '1',
        ));

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<int, string> $columns
     */
    private function orderHistoryTextCondition(string $alias, array $columns): string
    {
        $parts = array();
        $replacement = $this->sqlString(self::PLACEHOLDER_HISTORY);
        foreach ($columns as $column) {
            $field = $alias . '.' . $this->quoteIdentifier($column);
            $parts[] = '(' . $field . ' IS NOT NULL AND ' . $field . " <> '' AND " . $field . ' <> ' . $replacement . ')';
        }

        return implode(' OR ', $parts);
    }

    /**
     * @param array<int, int> $values
     */
    private function intList(array $values): string
    {
        $ints = array();
        foreach ($values as $value) {
            $ints[] = (string)max(0, (int)$value);
        }

        return implode(', ', array_unique($ints));
    }

    /**
     * @param array<string, mixed> $order
     */
    private function formatOrderIdentifier(array $order): string
    {
        $orderId = (int)($order['id'] ?? 0);
        $display = trim((string)($order['display_number'] ?? ''));
        if ($display === '') {
            return '#' . $orderId;
        }

        return preg_match('/^\d+$/', $display) === 1 ? '#' . $display : $display;
    }

    /**
     * @param array<int, string> $identifiers
     */
    private function compactOrderIdentifierRanges(array $identifiers, int $maxRanges = 20): string
    {
        $unique = array_values(array_unique(array_filter($identifiers, static function ($value): bool {
            return trim((string)$value) !== '';
        })));
        if (!$unique) {
            return '';
        }

        $numbers = array();
        foreach ($unique as $identifier) {
            if (preg_match('/^#(\d+)$/', (string)$identifier, $matches) !== 1) {
                return $this->compactNonNumericIdentifiers($unique, $maxRanges);
            }
            $numbers[] = (int)$matches[1];
        }

        sort($numbers, SORT_NUMERIC);
        $ranges = array();
        $start = $numbers[0];
        $prev = $numbers[0];
        $count = count($numbers);
        for ($i = 1; $i < $count; $i++) {
            $number = $numbers[$i];
            if ($number === $prev + 1) {
                $prev = $number;
                continue;
            }
            $ranges[] = $start === $prev ? '#' . $start : '#' . $start . '-#' . $prev;
            $start = $number;
            $prev = $number;
        }
        $ranges[] = $start === $prev ? '#' . $start : '#' . $start . '-#' . $prev;

        return $this->truncateRangeList($ranges, $maxRanges);
    }

    /**
     * @param array<int, string> $identifiers
     */
    private function compactNonNumericIdentifiers(array $identifiers, int $maxRanges): string
    {
        return $this->truncateRangeList($identifiers, $maxRanges);
    }

    /**
     * @param array<int, string> $ranges
     */
    private function truncateRangeList(array $ranges, int $maxRanges): string
    {
        $total = count($ranges);
        if ($total > $maxRanges) {
            $shown = array_slice($ranges, 0, $maxRanges);
            $shown[] = '... и ещё ' . ($total - $maxRanges);
            return implode(', ', $shown);
        }

        return implode(', ', $ranges);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPlaceholderNormalization(string $cutoff, bool $includeOrderParams, bool $includeContacts, bool $includeOrderHistory, bool $dryRun): array
    {
        $result = $this->emptyNormalizationResult();

        if ($includeOrderParams) {
            $result['breakdown']['order_params'] = $this->normalizeOrderParamPlaceholders($cutoff, $dryRun);
        }

        if ($includeContacts) {
            $result['breakdown']['contacts'] = $this->normalizeContactPlaceholders($dryRun);
        }

        if ($includeOrderHistory) {
            $result['breakdown']['order_history'] = $this->normalizeOrderHistoryPlaceholders($cutoff, $dryRun);
        }

        $result['total'] = array_sum($result['breakdown']);

        return $result;
    }

    private function normalizeOrderParamPlaceholders(string $cutoff, bool $dryRun): int
    {
        $expr = 'op.value';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order_params op
             INNER JOIN shop_order o ON o.id = op.order_id
             WHERE o.create_datetime < :cutoff
               AND op.name NOT IN (' . $this->sqlString('payment_name') . ', ' . $this->sqlString('shipping_name') . ')
               AND ' . $this->legacyPlaceholderCondition($expr)
        );
        $stmt->execute(array(':cutoff' => $cutoff));
        $count = (int)$stmt->fetchColumn();

        if ($count > 0 && !$dryRun) {
            $update = $this->pdo->prepare(
                'UPDATE shop_order_params op
                 INNER JOIN shop_order o ON o.id = op.order_id
                 SET op.value = ' . $this->legacyPlaceholderCaseSql($expr) . '
                 WHERE o.create_datetime < :cutoff
                   AND op.name NOT IN (' . $this->sqlString('payment_name') . ', ' . $this->sqlString('shipping_name') . ')
                   AND ' . $this->legacyPlaceholderCondition($expr)
            );
            $update->execute(array(':cutoff' => $cutoff));
        }

        return $count;
    }

    private function countDamagedServiceOrderParams(string $cutoff): int
    {
        $expr = 'op.value';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order_params op
             INNER JOIN shop_order o ON o.id = op.order_id
             WHERE o.create_datetime < :cutoff
               AND op.name IN (' . $this->sqlString('payment_name') . ', ' . $this->sqlString('shipping_name') . ')
               AND ' . $this->legacyPlaceholderCondition($expr)
        );
        $stmt->execute(array(':cutoff' => $cutoff));

        return (int)$stmt->fetchColumn();
    }

    private function normalizeContactPlaceholders(bool $dryRun): int
    {
        $count = 0;

        $contactColumns = array('name', 'firstname', 'middlename', 'lastname', 'title', 'company', 'jobtitle', 'about');
        foreach ($contactColumns as $column) {
            $count += $this->normalizeTableColumnPlaceholders('wa_contact', $column, $dryRun);
        }

        $count += $this->normalizeTableColumnPlaceholders('wa_contact_emails', 'email', $dryRun);
        $count += $this->normalizeTableColumnPlaceholders('wa_contact_data', 'value', $dryRun);
        $count += $this->normalizeTableColumnPlaceholders('wa_contact_data_text', 'value', $dryRun);

        $addressColumns = array(
            'name',
            'firstname',
            'middlename',
            'lastname',
            'company',
            'street',
            'house',
            'flat',
            'zip',
            'city',
            'region',
            'country',
            'address',
            'phone',
            'comment',
            'value',
            'data',
        );
        foreach ($addressColumns as $column) {
            $count += $this->normalizeTableColumnPlaceholders('wa_contact_addresses', $column, $dryRun);
        }

        return $count;
    }

    private function normalizeOrderHistoryPlaceholders(string $cutoff, bool $dryRun): int
    {
        $columns = $this->getOrderHistoryTextColumns();
        if (!$columns) {
            return 0;
        }

        $count = 0;
        foreach ($columns as $column) {
            $field = 'l.' . $this->quoteIdentifier($column);
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM shop_order_log l
                 INNER JOIN shop_order o ON o.id = l.order_id
                 WHERE o.create_datetime < :cutoff
                   AND ' . $this->legacyPlaceholderCondition($field)
            );
            $stmt->execute(array(':cutoff' => $cutoff));
            $columnCount = (int)$stmt->fetchColumn();
            $count += $columnCount;

            if ($columnCount > 0 && !$dryRun) {
                $update = $this->pdo->prepare(
                    'UPDATE shop_order_log l
                     INNER JOIN shop_order o ON o.id = l.order_id
                     SET l.' . $this->quoteIdentifier($column) . ' = ' . $this->legacyPlaceholderCaseSql($field) . '
                     WHERE o.create_datetime < :cutoff
                       AND ' . $this->legacyPlaceholderCondition($field)
                );
                $update->execute(array(':cutoff' => $cutoff));
            }
        }

        return $count;
    }

    private function normalizeTableColumnPlaceholders(string $table, string $column, bool $dryRun): int
    {
        if (!$this->tableExists($table) || !$this->tableHasColumn($table, $column)) {
            return 0;
        }

        $field = $this->quoteIdentifier($column);
        $stmt = $this->pdo->query(
            'SELECT COUNT(*)
             FROM ' . $this->quoteIdentifier($table) . '
             WHERE ' . $this->legacyPlaceholderCondition($field)
        );
        $count = (int)$stmt->fetchColumn();

        if ($count > 0 && !$dryRun) {
            $this->pdo->exec(
                'UPDATE ' . $this->quoteIdentifier($table) . '
                 SET ' . $field . ' = ' . $this->legacyPlaceholderCaseSql($field) . '
                 WHERE ' . $this->legacyPlaceholderCondition($field)
            );
        }

        return $count;
    }

    private function legacyPlaceholderCondition(string $expr): string
    {
        return '(' . $expr . ' = ' . $this->sqlString('Deleted') .
            ' OR (LEFT(' . $expr . ', 13) = ' . $this->sqlString('anon+contact_') . ' AND RIGHT(' . $expr . ', 16) = ' . $this->sqlString('@example.invalid') . ')' .
            ' OR (LEFT(' . $expr . ', 11) = ' . $this->sqlString('anon+order_') . ' AND RIGHT(' . $expr . ', 16) = ' . $this->sqlString('@example.invalid') . ')' .
            ' OR ' . $expr . ' LIKE ' . $this->sqlString('anon-contact-%') .
            ' OR ' . $expr . ' LIKE ' . $this->sqlString('anon-order-%') . ')';
    }

    private function legacyPlaceholderCaseSql(string $expr): string
    {
        return 'CASE
            WHEN ' . $expr . ' = ' . $this->sqlString('Deleted') . ' THEN ' . $this->sqlString(self::PLACEHOLDER_NAME) . '
            WHEN (LEFT(' . $expr . ', 13) = ' . $this->sqlString('anon+contact_') . ' AND RIGHT(' . $expr . ', 16) = ' . $this->sqlString('@example.invalid') . ')
              OR (LEFT(' . $expr . ', 11) = ' . $this->sqlString('anon+order_') . ' AND RIGHT(' . $expr . ', 16) = ' . $this->sqlString('@example.invalid') . ')
                THEN REPLACE(REPLACE(' . $expr . ', ' . $this->sqlString('anon+') . ', ' . $this->sqlString('obezlicheno+') . '), ' . $this->sqlString('@example.invalid') . ', ' . $this->sqlString('@' . self::PLACEHOLDER_EMAIL_DOMAIN) . ')
            WHEN ' . $expr . ' LIKE ' . $this->sqlString('anon-contact-%') . '
                THEN REPLACE(' . $expr . ', ' . $this->sqlString('anon-contact-') . ', ' . $this->sqlString('obezlicheno-contact-') . ')
            WHEN ' . $expr . ' LIKE ' . $this->sqlString('anon-order-%') . '
                THEN REPLACE(' . $expr . ', ' . $this->sqlString('anon-order-') . ', ' . $this->sqlString('obezlicheno-order-') . ')
            ELSE ' . $expr . '
        END';
    }

    private function sqlString(string $value): string
    {
        return (string)$this->pdo->quote($value);
    }

    private function cutoff(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
    }

    private function countAllOrdersOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM shop_order
             WHERE create_datetime < :cutoff'
        );
        $stmt->execute(array(':cutoff' => $cutoff));

        return (int)$stmt->fetchColumn();
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

    /**
     * @return array<int, int>
     */
    private function fetchContactCatchupBatch(string $cutoff, int $cursor, int $limit, string $scope): array
    {
        $this->refreshStateTableReady();

        $sql = 'SELECT DISTINCT o.contact_id
                FROM shop_order o
                WHERE o.create_datetime < :cutoff
                  AND o.contact_id IS NOT NULL
                  AND o.contact_id > :cursor
                  AND o.contact_id > 0
                  AND NOT EXISTS (
                      SELECT 1
                      FROM shop_order newer
                      WHERE newer.contact_id = o.contact_id
                        AND newer.create_datetime >= :cutoff_newer
                  )' . $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql() . '
                ORDER BY o.contact_id ASC';

        if ($limit > 0) {
            $sql .= ' LIMIT :limit';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cutoff_newer', $cutoff, PDO::PARAM_STR);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        if ($limit > 0) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        $ids = array();
        while (($contactId = $stmt->fetchColumn()) !== false) {
            $ids[] = (int)$contactId;
        }

        return $ids;
    }

    private function countContactCatchupCandidates(string $cutoff, int $cursor, string $scope): int
    {
        $this->refreshStateTableReady();

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT o.contact_id)
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.contact_id IS NOT NULL
               AND o.contact_id > :cursor
               AND o.contact_id > 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order newer
                   WHERE newer.contact_id = o.contact_id
                     AND newer.create_datetime >= :cutoff_newer
               )' . $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql()
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':cutoff_newer' => $cutoff,
            ':cursor' => $cursor,
        ));

        return (int)$stmt->fetchColumn();
    }

    private function hasContactCatchupAfterCursor(string $cutoff, int $cursor, string $scope): bool
    {
        $this->refreshStateTableReady();

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.contact_id IS NOT NULL
               AND o.contact_id > :cursor
               AND o.contact_id > 0
               AND NOT EXISTS (
                   SELECT 1
                   FROM shop_order newer
                   WHERE newer.contact_id = o.contact_id
                     AND newer.create_datetime >= :cutoff_newer
               )' . $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql() . '
             LIMIT 1'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':cutoff_newer' => $cutoff,
            ':cursor' => $cursor,
        ));

        return (bool)$stmt->fetchColumn();
    }

    private function countAlreadyProcessedOldContacts(string $cutoff): int
    {
        $this->refreshStateTableReady();
        if (!$this->stateTableReady) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT o.contact_id)
             FROM shop_order o
             INNER JOIN depersonalizer_state ds
                ON ds.entity_type = 'contact'
               AND ds.entity_id = o.contact_id
             WHERE o.create_datetime < :cutoff
               AND o.contact_id IS NOT NULL
               AND o.contact_id > 0"
        );
        $stmt->execute(array(':cutoff' => $cutoff));

        return (int)$stmt->fetchColumn();
    }

    private function countOldContactsWithNewerOrders(string $cutoff): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT o.contact_id)
             FROM shop_order o
             WHERE o.create_datetime < :cutoff
               AND o.contact_id IS NOT NULL
               AND o.contact_id > 0
               AND EXISTS (
                   SELECT 1
                   FROM shop_order newer
                   WHERE newer.contact_id = o.contact_id
                     AND newer.create_datetime >= :cutoff_newer
               )'
        );
        $stmt->execute(array(
            ':cutoff' => $cutoff,
            ':cutoff_newer' => $cutoff,
        ));

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
     */
    private function resolveRunId($raw): string
    {
        $candidate = trim((string)$raw);
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/', $candidate) === 1) {
            return $candidate;
        }

        return date('YmdHis') . '-' . bin2hex(random_bytes(4));
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

    private function refreshStateTableReady(): void
    {
        if (!$this->stateTableReady && $this->tableExists(self::STATE_TABLE)) {
            $this->stateTableReady = true;
        }
    }

    private function contactStateExclusionSql(): string
    {
        if (!$this->stateTableReady) {
            return '';
        }

        return " AND NOT EXISTS (
                   SELECT 1
                   FROM depersonalizer_state ds
                   WHERE ds.entity_type = 'contact'
                     AND ds.entity_id = o.contact_id
               )";
    }

    private function contactOrderScopeSql(string $scope): string
    {
        if ($scope === 'processed') {
            return " AND EXISTS (
                       SELECT 1
                       FROM shop_order_params processed
                       WHERE processed.order_id = o.id
                         AND processed.name = " . $this->sqlString(self::ORDER_PROCESSED_KEY) . "
                         AND processed.value = '1'
                   )";
        }

        if ($scope === 'unprocessed') {
            return " AND NOT EXISTS (
                       SELECT 1
                       FROM shop_order_params processed
                       WHERE processed.order_id = o.id
                         AND processed.name = " . $this->sqlString(self::ORDER_PROCESSED_KEY) . "
                         AND processed.value = '1'
                   )";
        }

        return '';
    }

    /**
     * @param mixed $raw
     */
    private function normalizeContactOrderScope($raw): string
    {
        $scope = trim((string)$raw);
        if (in_array($scope, array('processed', 'unprocessed', 'all'), true)) {
            return $scope;
        }

        return 'processed';
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
        return array_key_exists($column, $this->tableColumnTypes($table));
    }

    /**
     * @return array<string, string>
     */
    private function tableColumnTypes(string $table): array
    {
        if (!$this->tableExists($table)) {
            return array();
        }

        if (!array_key_exists($table, $this->tableColumnTypesCache)) {
            $safeTable = $this->quoteIdentifier($table);
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $safeTable);
            $columns = array();
            $types = array();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $field = (string)$row['Field'];
                $columns[] = $field;
                $types[$field] = (string)$row['Type'];
            }
            $this->tableColumnsCache[$table] = $columns;
            $this->tableColumnTypesCache[$table] = $types;
        }

        return $this->tableColumnTypesCache[$table];
    }

    private function getOrderDisplayColumn(): string
    {
        if ($this->orderDisplayColumnCache !== null) {
            return $this->orderDisplayColumnCache;
        }

        foreach (array('id_str', 'order_number', 'number', 'display_id', 'code') as $column) {
            if ($this->tableHasColumn('shop_order', $column)) {
                $this->orderDisplayColumnCache = $column;
                return $column;
            }
        }

        $this->orderDisplayColumnCache = '';
        return '';
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Небезопасный идентификатор: ' . $identifier);
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
                throw new RuntimeException('Не удалось создать директорию: ' . $path);
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
