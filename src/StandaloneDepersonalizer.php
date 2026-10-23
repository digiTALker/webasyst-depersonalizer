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
    private array $columnMetadataCache = array();
    private array $indexCache = array();

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
    public function preflight(bool $setupStateTable = false): array
    {
        $missing = array();
        foreach (array('shop_order', 'shop_order_params') as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }
        $this->refreshStateTableReady();
        $errors = array();
        if (!$missing) {
            try {
                $this->validateSchema($this->normalizeOptions(array('dry_run' => true)), false);
            } catch (PDOException $error) {
                throw $error;
            } catch (RuntimeException $error) {
                $errors[] = $error->getMessage();
            }
        }
        $optional = array();
        foreach (array('wa_contact', 'wa_contact_emails', 'wa_contact_data', 'wa_contact_data_text',
            'wa_contact_addresses', 'wa_contact_params') as $table) {
            $optional[$table] = $this->tableExists($table);
        }
        return array(
            'missing_required_tables' => $missing, 'schema_errors' => $errors,
            'optional_tables' => $optional + array('state_table' => self::STATE_TABLE,
                'state_table_ready' => $this->stateTableReady, 'state_table_error' => null),
            'safe_mode_notes' => array(
                'Предпросмотр и пробный запуск не создают таблиц и не изменяют данные.',
                'Заказы, контакты и история включаются независимо. Пустой выбор полей не разрешает их очистку.',
                'Таблица depersonalizer_state создается автоматически только после подтверждения реального запуска.',
                'wa_contact_params необязательна; строки заказов, контактов и адресов не удаляются.',
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
    public function preview(int $days, array $options = array()): array
    {
        $days = $this->normalizeDays($days);
        $cutoff = $this->cutoff($days);
        $keys = $this->candidateKeys($cutoff);
        if (empty($options['keys_reviewed'])) {
            $options['include_keys'] = array_values(array_filter($keys, fn(string $key): bool => !$this->isCommentKey($key)));
        }
        $options['days'] = $days;
        $options['dry_run'] = true;
        $options = $this->normalizeOptions($options);
        $this->validateSchema($options, false);
        if ($options['wipe_comments']) {
            foreach ($keys as $key) {
                if ($this->isCommentKey($key)) {
                    $options['include_keys'][] = $key;
                }
            }
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order o WHERE o.create_datetime < :cutoff AND (' .
            $this->orderChangeCondition($options) . ')');
        $stmt->execute(array(':cutoff' => $cutoff));
        return array(
            'days' => $days, 'cutoff' => $cutoff,
            'old_orders_total' => $this->countAllOrdersOlderThan($cutoff),
            'total_orders' => $options['anonymize_orders'] ? (int)$stmt->fetchColumn() : 0,
            'candidate_keys' => $keys,
            'contact_catchup' => $this->previewContactCatchup($cutoff),
            'order_history' => $this->previewOrderHistory($cutoff),
            'placeholder_normalization' => $this->previewPlaceholderNormalization($cutoff, $options),
            'damaged_service_fields' => $this->countDamagedServiceOrderParams($cutoff),
        );
    }

    /**
     * Execute one batch.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runBatch(array $plan): array
    {
        if (!isset($plan['options'], $plan['phase'], $plan['cutoff'], $plan['totals'])) {
            throw new RuntimeException('Сначала создайте подтвержденный план запуска.');
        }
        $this->runId = $plan['run_id'];
        $options = $plan['options'];
        $dryRun = $options['dry_run'];
        $this->validateSchema($options, !$dryRun);
        $phase = $plan['phases'][$plan['phase']] ?? '';
        $result = array(
            'run_id' => $this->runId, 'cutoff' => $plan['cutoff'], 'dry_run' => $dryRun,
            'processed_orders' => array(), 'processed_order_numbers' => array(), 'skipped_orders' => array(),
            'processed_contacts' => array(), 'skipped_contacts' => array(),
            'processed_history_rows' => 0, 'skipped_history_rows' => 0,
            'normalized_placeholders' => 0, 'normalization_breakdown' => array('order_params' => 0, 'contacts' => 0, 'order_history' => 0),
            'phase' => $phase, 'warnings' => array(), 'committed' => false,
        );
        $batchLog = $this->writeBatchLog(array('run_id' => $this->runId, 'status' => 'prepared', 'dry_run' => $dryRun));
        try {
            if (!$dryRun) {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->beginTransaction();
                $this->claimRunLease($plan['lease_id'], false);
            }

            $seen = 0;
            if ($phase === 'contacts') {
                $ids = $this->fetchContactCatchupBatch($plan['cutoff'], $plan['contact_cursor'], $options['limit'],
                    $options['contact_order_scope'], $plan['contact_max']);
                foreach ($ids as $id) {
                    $reason = $this->getContactSkipReason($id, $plan['cutoff'], !$dryRun);
                    if ($reason !== null) {
                        $result['skipped_contacts'][$id] = $reason;
                        continue;
                    }
                    if ($this->isContactAlreadyProcessed($id)) {
                        $result['normalization_breakdown']['contacts'] += $this->normalizeContactPlaceholders($id, $dryRun);
                    } elseif (!$dryRun) {
                        $this->updateContactCore($id);
                        $this->updateContactEmails($id);
                        $this->updateContactData($id);
                        $this->updateContactDataText($id);
                        $this->updateContactAddresses($id);
                    }
                    if (!$dryRun) {
                        $this->markContactProcessed($id, 'anonymized_v2');
                    }
                    $result['processed_contacts'][] = $id;
                }
                $seen = count($ids);
                if ($ids) {
                    $plan['contact_cursor'] = max($ids);
                }
                $more = (bool)$this->fetchContactCatchupBatch($plan['cutoff'], $plan['contact_cursor'], 1,
                    $options['contact_order_scope'], $plan['contact_max']);
            } elseif ($phase === 'orders') {
                $orders = $this->fetchOrdersBatch($plan['cutoff'], $plan['cursor'], $options['limit'], $options, $plan['order_max']);
                foreach ($orders as $order) {
                    $id = (int)$order['id'];
                    $params = $this->fetchOrderParams($id);
                    $changed = false;
                    foreach ($options['include_keys'] as $key) {
                        if (!array_key_exists($key, $params)) {
                            continue;
                        }
                        $replacement = $this->maskOrderParam($key, $params[$key], $id);
                        if ($params[$key] === $replacement) {
                            continue;
                        }
                        if (!$dryRun) {
                            if (!$changed && $options['keep_geo']) {
                                $this->preserveGeoSnapshot($id, $params);
                            }
                            $this->setOrderParam($id, $key, $replacement);
                        }
                        if ($this->isOrderAlreadyProcessed($params) && $this->normalizeLegacyValue($params[$key]) !== $params[$key]) {
                            $result['normalization_breakdown']['order_params']++;
                        }
                        $changed = true;
                    }
                    if ($options['wipe_comments'] && $this->tableHasColumn('shop_order', 'comment') && (string)($order['comment'] ?? '') !== '') {
                        if (!$dryRun) {
                            $stmt = $this->pdo->prepare("UPDATE shop_order SET comment = '' WHERE id = :id");
                            $stmt->execute(array(':id' => $id));
                        }
                        $changed = true;
                    }
                    if ($changed) {
                        if (!$dryRun) {
                            $this->setOrderParam($id, self::ORDER_PROCESSED_KEY, '1');
                            $this->setOrderParam($id, self::ORDER_PROCESSED_AT_KEY, date('Y-m-d H:i:s'));
                        }
                        $result['processed_orders'][] = $id;
                        $result['processed_order_numbers'][] = $this->formatOrderIdentifier($order);
                    } else {
                        $result['skipped_orders'][$id] = 'no_changes';
                    }
                }
                $seen = count($orders);
                if ($orders) {
                    $plan['cursor'] = (int)$orders[count($orders) - 1]['id'];
                }
                $more = (bool)$this->fetchOrdersBatch($plan['cutoff'], $plan['cursor'], 1, $options, $plan['order_max']);
            } elseif ($phase === 'history') {
                $rows = $this->fetchHistoryRows($plan, $options['limit']);
                $columns = $this->getOrderHistoryTextColumns();
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        if ($row[$column] === null || $row[$column] === '' || $row[$column] === self::PLACEHOLDER_HISTORY) {
                            continue;
                        }
                        if ($this->normalizeLegacyValue((string)$row[$column]) !== (string)$row[$column]) {
                            $result['normalization_breakdown']['order_history']++;
                        }
                        if (!$dryRun) {
                            $stmt = $this->pdo->prepare('UPDATE shop_order_log SET ' . $this->quoteIdentifier($column) . ' = :value WHERE id = :id');
                            $stmt->execute(array(':value' => self::PLACEHOLDER_HISTORY, ':id' => $row['id']));
                        }
                    }
                    if (!$dryRun) {
                        $this->setOrderParam((int)$row['order_id'], self::ORDER_LOG_PROCESSED_KEY, '1');
                        $this->setOrderParam((int)$row['order_id'], self::ORDER_LOG_PROCESSED_AT_KEY, date('Y-m-d H:i:s'));
                    }
                }
                $seen = count($rows);
                $result['processed_history_rows'] = $seen;
                if ($rows) {
                    $plan['history_cursor'] = (int)$rows[count($rows) - 1]['id'];
                }
                $more = (bool)$this->fetchHistoryRows($plan, 1);
            } else {
                $more = false;
            }
            $plan['progress'] += $seen;
            if (!$more) {
                $plan['phase']++;
            }
            $done = $plan['phase'] >= count($plan['phases']);
            $result['normalized_placeholders'] = array_sum($result['normalization_breakdown']);
            $result['processed_order_numbers_summary'] = $this->compactOrderIdentifierRanges($result['processed_order_numbers']);
            $result += array(
                'done' => $done, 'progress' => $plan['progress'], 'total' => array_sum($plan['totals']),
                'cursor' => $plan['cursor'], 'contact_cursor' => $plan['contact_cursor'], 'history_cursor' => $plan['history_cursor'],
                'contact_total' => $plan['totals']['contacts'], 'history_total' => $plan['totals']['history'],
                'batch_count' => $seen, 'batch_log' => $batchLog,
            );
            $audit = $result + array('options' => $options, 'selected_include_keys' => $options['include_keys'], 'timestamp' => date('c'));
            $audit['status'] = $dryRun ? 'dry_run' : 'awaiting_commit';
            $this->writeJsonFile($batchLog, $audit);
            $this->log('info', 'Пакет подготовлен', array('run_id' => $this->runId, 'dry_run' => $dryRun, 'batch_log' => $batchLog));
            if (!$dryRun) {
                if ($done) {
                    $this->finishRun($plan['lease_id']);
                }
                $this->pdo->commit();
                $result['committed'] = true;
            }
        } catch (Throwable $error) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            try {
                $this->writeJsonFile($batchLog, array('run_id' => $this->runId, 'status' => 'failed_check_database',
                    'error_type' => get_class($error), 'error_code' => (string)$error->getCode()));
            } catch (Throwable $ignored) {
                // Do not hide the original failure with a second logging error.
            }
            throw $error;
        }
        if (!$dryRun) {
            try {
                $audit['status'] = 'committed';
                $audit['committed'] = true;
                $this->writeJsonFile($batchLog, $audit);
            } catch (Throwable $error) {
                $result['warnings'][] = 'Изменения уже записаны в БД, но статус JSON-лога не обновлен. Не повторяйте пакет; проверьте журнал.';
            }
        }
        $result['plan'] = $plan;
        return $result;
    }




    /**
     * @param string $cutoff
     * @param int $cursor
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrdersBatch(string $cutoff, int $cursor, int $limit, array $options, int $maximum): array
    {
        $select = 'o.id';
        $display = $this->getOrderDisplayColumn();
        if ($display !== '') {
            $select .= ', o.' . $this->quoteIdentifier($display) . ' AS display_number';
        }
        if ($this->tableHasColumn('shop_order', 'comment')) {
            $select .= ', o.comment';
        }
        $stmt = $this->pdo->prepare('SELECT ' . $select . ' FROM shop_order o
            WHERE o.create_datetime < :cutoff AND o.id > :cursor AND o.id <= :maximum
              AND (' . $this->orderChangeCondition($options) . ') ORDER BY o.id LIMIT :limit');
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':maximum', $maximum, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $hasContactId = $this->tableHasColumn('shop_order', 'contact_id');
        $alreadyProcessed = $hasContactId ? $this->countAlreadyProcessedOldContacts($cutoff) : 0;
        $withNewerOrders = $hasContactId ? $this->countOldContactsWithNewerOrders($cutoff) : 0;
        $emptyScopes = array(
            'processed' => $this->emptyContactScopePreview(),
            'unprocessed' => $this->emptyContactScopePreview(),
            'all' => $this->emptyContactScopePreview(),
        );

        if (!$hasContactId || !$this->tableHasColumn('wa_contact', 'id')) {
            return array(
                'available' => false,
                'candidate_contacts' => 0,
                'eligible_contacts' => 0,
                'already_processed_contacts' => $alreadyProcessed,
                'contacts_with_newer_orders' => $withNewerOrders,
                'protected_contacts' => 0,
                'scopes' => $emptyScopes,
                'note' => 'Нет wa_contact.id или shop_order.contact_id; обработка контактов недоступна.',
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
        $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT o.contact_id) FROM shop_order o
            INNER JOIN wa_contact c ON c.id = o.contact_id
            WHERE o.create_datetime < :cutoff AND o.contact_id > 0
              AND NOT EXISTS (SELECT 1 FROM shop_order newer WHERE newer.contact_id = o.contact_id AND newer.create_datetime >= :cutoff_newer)' .
            $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql() . ' AND (' . $this->contactProtectionSql('c') . ')');
        $stmt->execute(array(':cutoff' => $cutoff, ':cutoff_newer' => $cutoff));
        $eligible = (int)$stmt->fetchColumn();
        return array('candidate_contacts' => $candidateContacts, 'eligible_contacts' => $eligible,
            'protected_contacts' => $candidateContacts - $eligible);
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
            $reason = 'safe_columns_empty_or_clean';
            $note = 'Безопасные текстовые колонки пусты или уже очищены. Видимая история может формироваться из структурированных params/data, которые этот режим не меняет. Старые метки не исключают новые строки истории.';
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
    private function previewPlaceholderNormalization(string $cutoff, array $options): array
    {
        $result = $this->emptyNormalizationResult();
        if ($options['anonymize_orders'] && $options['include_keys']) {
            $names = implode(',', array_map(fn(string $key): string => $this->sqlString($key), $options['include_keys']));
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order_params op
                INNER JOIN shop_order o ON o.id = op.order_id WHERE o.create_datetime < :cutoff
                  AND op.name IN (' . $names . ') AND EXISTS (SELECT 1 FROM shop_order_params m
                    WHERE m.order_id = o.id AND m.name = ' . $this->sqlString(self::ORDER_PROCESSED_KEY) . " AND m.value = '1')
                  AND " . $this->legacyPlaceholderCondition('op.value'));
            $stmt->execute(array(':cutoff' => $cutoff));
            $result['breakdown']['order_params'] = (int)$stmt->fetchColumn();
        }
        if ($options['anonymize_contacts'] && $this->tableExists('wa_contact') && $this->stateTableReady) {
            foreach ($this->contactNormalizationColumns() as $table => $columns) {
                if (!$this->tableExists($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (!$this->tableHasColumn($table, $column)) {
                        continue;
                    }
                    $id = $table === 'wa_contact' ? 't.id' : 't.contact_id';
                    $sql = 'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' t
                        INNER JOIN wa_contact c ON c.id = ' . $id . '
                        INNER JOIN depersonalizer_state ds ON ds.entity_id = c.id AND ds.entity_type = ' . $this->sqlString('contact') . "
                        WHERE COALESCE(ds.note, '') <> 'anonymized_v2' AND (" . $this->contactProtectionSql('c') . ')
                          AND EXISTS (SELECT 1 FROM shop_order o WHERE o.contact_id = c.id AND o.create_datetime < :cutoff' .
                        $this->contactOrderScopeSql($options['contact_order_scope']) . ')
                          AND NOT EXISTS (SELECT 1 FROM shop_order newer WHERE newer.contact_id = c.id AND newer.create_datetime >= :cutoff_newer)
                          AND ' . $this->legacyPlaceholderCondition('t.' . $this->quoteIdentifier($column));
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute(array(':cutoff' => $cutoff, ':cutoff_newer' => $cutoff));
                    $result['breakdown']['contacts'] += (int)$stmt->fetchColumn();
                }
            }
        }
        if ($options['anonymize_order_history']) {
            foreach ($this->getOrderHistoryTextColumns() as $column) {
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order_log l
                    INNER JOIN shop_order o ON o.id = l.order_id WHERE o.create_datetime < :cutoff AND ' .
                    $this->legacyPlaceholderCondition('l.' . $this->quoteIdentifier($column)));
                $stmt->execute(array(':cutoff' => $cutoff));
                $result['breakdown']['order_history'] += (int)$stmt->fetchColumn();
            }
        }
        $result['total'] = array_sum($result['breakdown']);
        return $result;
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
        if (preg_match('/^(geo_|_depersonalizer)/i', $key)) {
            return false;
        }
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
                OR field LIKE '%phone%'
                OR field LIKE '%email%'
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

    private function getContactSkipReason(int $contactId, string $cutoff, bool $lock = false): ?string
    {
        $protection = $this->getContactAccountProtectionReason($contactId, $lock);
        if ($protection !== null) {
            return $protection;
        }
        if ($lock) {
            // A current read plus the contact_id next-key range prevents a concurrent order insert until commit.
            $index = $this->contactOrderIndex();
            $stmt = $this->pdo->prepare('SELECT id, create_datetime FROM shop_order FORCE INDEX (' . $this->quoteIdentifier($index) . ')
                WHERE contact_id = :contact_id FOR UPDATE');
            $stmt->execute(array(':contact_id' => $contactId));
            $hasOldOrder = false;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ((string)$row['create_datetime'] >= $cutoff) {
                    return 'has_newer_orders';
                }
                $hasOldOrder = true;
            }
            if (!$hasOldOrder) {
                return 'no_old_orders';
            }
        } else {
            $stmt = $this->pdo->prepare('SELECT 1 FROM shop_order WHERE contact_id = :id AND create_datetime >= :cutoff LIMIT 1');
            $stmt->execute(array(':id' => $contactId, ':cutoff' => $cutoff));
            if ($stmt->fetchColumn() !== false) {
                return 'has_newer_orders';
            }
        }
        if ($this->stateTableReady) {
            $stmt = $this->pdo->prepare("SELECT note FROM depersonalizer_state
                WHERE entity_type = 'contact' AND entity_id = :id" . ($lock ? ' FOR UPDATE' : ''));
            $stmt->execute(array(':id' => $contactId));
            if ($stmt->fetchColumn() === 'anonymized_v2') {
                return 'already_processed';
            }
        }
        return null;
    }

    private function getContactAccountProtectionReason(int $contactId, bool $lock = false): ?string
    {
        $columns = array('id');
        foreach (array('is_staff', 'is_user', 'login') as $column) {
            if ($this->tableHasColumn('wa_contact', $column)) {
                $columns[] = $column;
            }
        }
        $stmt = $this->pdo->prepare('SELECT ' . implode(',', array_map(fn(string $name): string => $this->quoteIdentifier($name), $columns)) .
            ' FROM wa_contact WHERE id = :id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(array(':id' => $contactId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 'contact_missing';
        }
        if ((int)($row['is_staff'] ?? 0) !== 0) {
            return 'staff_contact';
        }
        if ((int)($row['is_user'] ?? 0) !== 0) {
            return 'user_contact';
        }
        if (trim((string)($row['login'] ?? '')) !== '') {
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
     * @return array<int, string>
     */
    private function getOrderHistoryTextColumns(): array
    {
        if (!$this->tableHasColumn('shop_order_log', 'id') || !$this->tableHasColumn('shop_order_log', 'order_id')) {
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
        );
        if (!in_array($column, $allowed, true)) {
            return false;
        }

        return $this->isTextColumnType($type);
    }

    private function isTextColumnType(string $type): bool
    {
        return preg_match('/^(?:char|varchar|mediumtext|longtext|tinytext|text)(?:\(|$)/i', $type) === 1;
    }

    private function countAllOrderHistoryRows(string $cutoff): int
    {
        if (!$this->tableHasColumn('shop_order_log', 'order_id')) {
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
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order_log l
            INNER JOIN shop_order o ON o.id = l.order_id
            WHERE o.create_datetime < :cutoff AND l.id > :cursor AND (' . $this->orderHistoryTextCondition('l', $columns) . ')');
        $stmt->execute(array(':cutoff' => $cutoff, ':cursor' => $cursor));
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
     */
    private function orderHistoryTextCondition(string $alias, array $columns): string
    {
        $parts = array();
        $replacement = $this->sqlString(self::PLACEHOLDER_HISTORY);
        foreach ($columns as $column) {
            $field = $alias . '.' . $this->quoteIdentifier($column);
            $parts[] = '(' . $field . ' IS NOT NULL AND ' . $field . " <> '' AND BINARY " . $field . ' <> BINARY ' . $replacement . ')';
        }
        return $parts ? implode(' OR ', $parts) : '1 = 0';
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
            if (preg_match('/^#(\d+)$/', (string)$identifier, $matches) !== 1 || (string)(int)$matches[1] !== $matches[1]) {
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

    private function normalizeContactPlaceholders(int $contactId, bool $dryRun): int
    {
        $count = 0;
        foreach ($this->contactNormalizationColumns() as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $idColumn = $table === 'wa_contact' ? 'id' : 'contact_id';
            foreach ($columns as $column) {
                if (!$this->tableHasColumn($table, $column)) {
                    continue;
                }
                $field = $this->quoteIdentifier($column);
                $stmt = $this->pdo->prepare('SELECT ' . $field . ' FROM ' . $this->quoteIdentifier($table) .
                    ' WHERE ' . $idColumn . ' = :id');
                $stmt->execute(array(':id' => $contactId));
                $changes = array();
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    $replacement = $this->normalizeLegacyValue($value);
                    if ($replacement !== $value) {
                        $count++;
                        $changes[$value] = $replacement;
                    }
                }
                if (!$dryRun) {
                    foreach ($changes as $value => $replacement) {
                        $update = $this->pdo->prepare('UPDATE ' . $this->quoteIdentifier($table) .
                            ' SET ' . $field . ' = :replacement WHERE ' . $idColumn . ' = :id AND BINARY ' . $field . ' = BINARY :old');
                        $update->execute(array(':replacement' => $replacement, ':id' => $contactId, ':old' => $value));
                    }
                }
            }
        }
        return $count;
    }



    private function legacyPlaceholderCondition(string $expr): string
    {
        return '(BINARY ' . $expr . ' = BINARY ' . $this->sqlString('Deleted') .
            ' OR BINARY ' . $expr . ' REGEXP ' . $this->sqlString('^anon[+](contact_[0-9]+(_[0-9]+)?|order_[0-9]+)@example[.]invalid$') .
            ' OR BINARY ' . $expr . ' REGEXP ' . $this->sqlString('^anon-(contact|order)-[a-f0-9]{40}$') . ')';
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





    /**
     * @return array<int, int>
     */
    private function fetchContactCatchupBatch(string $cutoff, int $cursor, int $limit, string $scope, int $maximum = PHP_INT_MAX): array
    {
        $this->refreshStateTableReady();
        $stmt = $this->pdo->prepare('SELECT DISTINCT o.contact_id FROM shop_order o
            WHERE o.create_datetime < :cutoff AND o.contact_id > :cursor AND o.contact_id > 0 AND o.contact_id <= :maximum
              AND NOT EXISTS (SELECT 1 FROM shop_order newer WHERE newer.contact_id = o.contact_id AND newer.create_datetime >= :cutoff_newer)' .
            $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql() . '
            ORDER BY o.contact_id LIMIT :limit');
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':cutoff_newer', $cutoff);
        $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':maximum', $maximum, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function countContactCatchupCandidates(string $cutoff, int $cursor, string $scope, int $maximum = PHP_INT_MAX): int
    {
        $this->refreshStateTableReady();
        $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT o.contact_id) FROM shop_order o
            WHERE o.create_datetime < :cutoff AND o.contact_id > :cursor AND o.contact_id > 0 AND o.contact_id <= :maximum
              AND NOT EXISTS (SELECT 1 FROM shop_order newer WHERE newer.contact_id = o.contact_id AND newer.create_datetime >= :cutoff_newer)' .
            $this->contactOrderScopeSql($scope) . $this->contactStateExclusionSql());
        $stmt->execute(array(':cutoff' => $cutoff, ':cursor' => $cursor, ':maximum' => $maximum, ':cutoff_newer' => $cutoff));
        return (int)$stmt->fetchColumn();
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
            throw new RuntimeException('Список полей должен быть массивом.');
        }
        if (count($raw) > 512) {
            throw new RuntimeException('Выберите не более 512 полей за один запуск.');
        }

        $keys = array();
        foreach ($raw as $value) {
            if (!is_string($value) || strlen($value) > 255) {
                throw new RuntimeException('Некорректное имя поля в списке обработки.');
            }
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
        return " AND NOT EXISTS (SELECT 1 FROM depersonalizer_state ds
            WHERE ds.entity_type = 'contact' AND ds.entity_id = o.contact_id AND ds.note = 'anonymized_v2')";
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

        foreach (array('id_str', 'order_number', 'number', 'display_id') as $column) {
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
        $dateDir = $this->logDir . DIRECTORY_SEPARATOR . 'batches' . DIRECTORY_SEPARATOR . date('Y-m-d');
        $this->ensureDirectory($dateDir);
        $path = $dateDir . DIRECTORY_SEPARATOR . 'batch-' . date('H-i-s') . '-' . bin2hex(random_bytes(12)) . '.json';
        $this->writeJsonFile($path, $payload);
        return $path;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = array()): void
    {
        $line = json_encode(array('time' => date('c'), 'level' => strtoupper($level), 'message' => $message,
            'context' => $context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (@file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException('Не удалось записать основной журнал. Пакет отменен.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать каталог журналов.');
        }
        foreach (array('.htaccess' => "Require all denied\n", 'index.html' => '') as $name => $content) {
            $file = $path . DIRECTORY_SEPARATOR . $name;
            if (!is_file($file) && @file_put_contents($file, $content, LOCK_EX) !== strlen($content)) {
                throw new RuntimeException('Не удалось защитить каталог журналов.');
            }
        }
    }
    /**
     * Build a server-owned plan once. Contacts run before order markers can change their scope.
     */
    public function createRun(array $options): array
    {
        $options = $this->normalizeOptions($options);
        $this->validateSchema($options, !$options['dry_run']);
        if (!$options['anonymize_orders'] && !$options['anonymize_contacts'] && !$options['anonymize_order_history']) {
            throw new RuntimeException('Выберите хотя бы один вид обработки.');
        }
        $runId = $this->resolveRunId($options['run_id']);
        $leaseId = bin2hex(random_bytes(24));
        unset($options['run_id']);
        if (!$options['dry_run']) {
            $this->ensureStateTable();
            $this->validateStateSchema();
            $this->pdo->beginTransaction();
            try {
                $this->claimRunLease($leaseId, true);
                $this->pdo->commit();
            } catch (Throwable $error) {
                $this->pdo->rollBack();
                throw $error;
            }
        }
        try {
            $cutoff = $this->cutoff($options['days']);
            if ($options['anonymize_orders'] && $options['wipe_comments']) {
                foreach ($this->candidateKeys($cutoff) as $key) {
                    if ($this->isCommentKey($key)) {
                        $options['include_keys'][] = $key;
                    }
                }
                $options['include_keys'] = array_values(array_unique($options['include_keys']));
            }
            $plan = array(
                'run_id' => $runId, 'lease_id' => $leaseId, 'options' => $options, 'cutoff' => $cutoff,
                'cursor' => 0, 'contact_cursor' => 0, 'history_cursor' => 0,
                'phase' => 0, 'progress' => 0, 'phases' => array(),
                'order_max' => $this->maximumId('shop_order'),
                'contact_max' => $options['anonymize_contacts'] ? $this->maximumId('wa_contact') : 0,
                'history_max' => $options['anonymize_order_history'] ? $this->maximumId('shop_order_log') : 0,
                'totals' => array('contacts' => 0, 'orders' => 0, 'history' => 0),
            );
            if ($options['anonymize_contacts']) {
                $plan['phases'][] = 'contacts';
                $plan['totals']['contacts'] = $this->countContactCatchupCandidates($cutoff, 0, $options['contact_order_scope'], $plan['contact_max']);
            }
            if ($options['anonymize_orders']) {
                $plan['phases'][] = 'orders';
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order o WHERE o.create_datetime < :cutoff
                    AND o.id <= :maximum AND (' . $this->orderChangeCondition($options) . ')');
                $stmt->execute(array(':cutoff' => $cutoff, ':maximum' => $plan['order_max']));
                $plan['totals']['orders'] = (int)$stmt->fetchColumn();
            }
            if ($options['anonymize_order_history']) {
                $plan['phases'][] = 'history';
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM shop_order_log l
                    INNER JOIN shop_order o ON o.id = l.order_id
                    WHERE o.create_datetime < :cutoff AND l.id <= :maximum AND (' .
                    $this->orderHistoryTextCondition('l', $this->getOrderHistoryTextColumns()) . ')');
                $stmt->execute(array(':cutoff' => $cutoff, ':maximum' => $plan['history_max']));
                $plan['totals']['history'] = (int)$stmt->fetchColumn();
            }
            return $plan;
        } catch (Throwable $error) {
            if (!$options['dry_run']) {
                $this->finishRun($leaseId);
            }
            throw $error;
        }
    }

    private function normalizeOptions(array $options): array
    {
        if (array_key_exists('dry_run', $options) && !is_bool($options['dry_run'])) {
            throw new RuntimeException('Параметр dry_run должен быть логическим значением.');
        }
        $dryRun = !array_key_exists('dry_run', $options) || $options['dry_run'] === true;
        if (!$dryRun && (($options['backup_confirmed'] ?? false) !== true || ($options['confirmation_phrase'] ?? '') !== 'ANONYMIZE')) {
            throw new RuntimeException('Для реального обезличивания нужна отметка о резервной копии и точная фраза ANONYMIZE.');
        }
        $keys = array_values(array_filter($this->normalizeIncludeKeys($options['include_keys'] ?? array()),
            fn(string $key): bool => $this->isPiiKey($key)));
        return array(
            'days' => $this->normalizeDays((int)($options['days'] ?? 365)),
            'limit' => $this->normalizeLimit((int)($options['limit'] ?? 200)),
            'dry_run' => $dryRun,
            'anonymize_orders' => ($options['anonymize_orders'] ?? false) === true && empty($options['contact_catchup_only']),
            'anonymize_contacts' => ($options['anonymize_contacts'] ?? false) === true,
            'contact_order_scope' => $this->normalizeContactOrderScope($options['contact_order_scope'] ?? 'processed'),
            'anonymize_order_history' => ($options['anonymize_order_history'] ?? false) === true,
            'keep_geo' => ($options['keep_geo'] ?? false) === true,
            'wipe_comments' => ($options['wipe_comments'] ?? false) === true,
            'include_keys' => $keys,
            'run_id' => (string)($options['run_id'] ?? ''),
        );
    }

    private function maximumId(string $table): int
    {
        return (int)$this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . $this->quoteIdentifier($table))->fetchColumn();
    }

    private function candidateKeys(string $cutoff): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT op.name FROM shop_order_params op
            INNER JOIN shop_order o ON o.id = op.order_id WHERE o.create_datetime < :cutoff');
        $stmt->execute(array(':cutoff' => $cutoff));
        $keys = array();
        while (($key = $stmt->fetchColumn()) !== false) {
            if ($this->isPiiKey((string)$key)) {
                $keys[] = (string)$key;
            }
        }
        sort($keys, SORT_STRING);
        return $keys;
    }

    private function orderChangeCondition(array $options): string
    {
        $cases = array();
        $keys = array();
        foreach ($options['include_keys'] as $key) {
            $name = $this->sqlString($key);
            $keys[] = $name;
            if (preg_match('/email/i', $key)) {
                $replacement = "CONCAT('obezlicheno+order_', o.id, '@" . self::PLACEHOLDER_EMAIL_DOMAIN . "')";
            } elseif (preg_match('/phone/i', $key)) {
                $replacement = "CONCAT('obezlicheno-order-', SHA1(CAST(o.id AS CHAR)))";
            } else {
                $replacement = $this->sqlString($this->maskOrderParam($key, '', 0));
            }
            $cases[] = 'WHEN op.name = ' . $name . ' THEN ' . $replacement;
        }
        $conditions = array();
        if ($keys) {
            $conditions[] = 'EXISTS (SELECT 1 FROM shop_order_params op WHERE op.order_id = o.id
                AND op.name IN (' . implode(',', $keys) . ")
                AND BINARY COALESCE(op.value, '') <> BINARY (CASE " . implode(' ', $cases) . " ELSE '' END))";
        }
        if ($options['wipe_comments'] && $this->tableHasColumn('shop_order', 'comment')) {
            $conditions[] = "COALESCE(o.comment, '') <> ''";
        }
        return $conditions ? implode(' OR ', $conditions) : '1 = 0';
    }

    private function fetchHistoryRows(array $plan, int $limit): array
    {
        $columns = $this->getOrderHistoryTextColumns();
        $select = array('l.id', 'l.order_id');
        foreach ($columns as $column) {
            $select[] = 'l.' . $this->quoteIdentifier($column);
        }
        $stmt = $this->pdo->prepare('SELECT ' . implode(',', $select) . '
            FROM shop_order_log l INNER JOIN shop_order o ON o.id = l.order_id
            WHERE o.create_datetime < :cutoff AND l.id > :cursor AND l.id <= :maximum
              AND (' . $this->orderHistoryTextCondition('l', $columns) . ') ORDER BY l.id LIMIT :limit');
        $stmt->bindValue(':cutoff', $plan['cutoff']);
        $stmt->bindValue(':cursor', $plan['history_cursor'], PDO::PARAM_INT);
        $stmt->bindValue(':maximum', $plan['history_max'], PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function claimRunLease(string $runId, bool $newRun): void
    {
        if ($newRun) {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO depersonalizer_state
                (entity_type, entity_id, processed_at, run_id, note)
                VALUES ('run_lock', 0, NOW(), :run_id, 'lease')");
            $stmt->execute(array(':run_id' => $runId));
        }
        $row = $this->pdo->query("SELECT run_id, TIMESTAMPDIFF(SECOND, processed_at, NOW()) AS age
            FROM depersonalizer_state WHERE entity_type = 'run_lock' AND entity_id = 0 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['run_id'] !== $runId && (!$newRun || (int)$row['age'] < 1800))) {
            throw new RuntimeException('Другой реальный запуск активен или этот запуск истек. Остановите его либо дождитесь 30 минут бездействия.');
        }
        $stmt = $this->pdo->prepare("UPDATE depersonalizer_state SET run_id = :run_id, processed_at = NOW()
            WHERE entity_type = 'run_lock' AND entity_id = 0");
        $stmt->execute(array(':run_id' => $runId));
    }

    public function finishRun(string $runId): void
    {
        if ($this->tableExists(self::STATE_TABLE)) {
            $stmt = $this->pdo->prepare("DELETE FROM depersonalizer_state WHERE entity_type = 'run_lock' AND entity_id = 0 AND run_id = :run_id");
            $stmt->execute(array(':run_id' => $runId));
        }
    }

    private function normalizeLegacyValue(string $value): string
    {
        if ($value === 'Deleted') {
            return self::PLACEHOLDER_NAME;
        }
        if (preg_match('/^anon\\+(contact_[0-9]+(?:_[0-9]+)?|order_[0-9]+)@example\\.invalid$/D', $value, $matches)) {
            return 'obezlicheno+' . $matches[1] . '@' . self::PLACEHOLDER_EMAIL_DOMAIN;
        }
        if (preg_match('/^anon-(contact|order)-([a-f0-9]{40})$/D', $value, $matches)) {
            return 'obezlicheno-' . $matches[1] . '-' . $matches[2];
        }
        return $value;
    }

    private function contactNormalizationColumns(): array
    {
        return array(
            'wa_contact' => array('name', 'firstname', 'middlename', 'lastname', 'title', 'company', 'jobtitle', 'about'),
            'wa_contact_emails' => array('email'),
            'wa_contact_data' => array('value'),
            'wa_contact_data_text' => array('value'),
            'wa_contact_addresses' => array('name', 'firstname', 'middlename', 'lastname', 'company',
                'street', 'house', 'flat', 'zip', 'city', 'region', 'country', 'address', 'phone', 'comment', 'value', 'data'),
        );
    }

    private function contactProtectionSql(string $alias): string
    {
        $parts = array('1 = 1');
        foreach (array('is_staff', 'is_user') as $column) {
            if ($this->tableHasColumn('wa_contact', $column)) {
                $parts[] = 'COALESCE(' . $alias . '.' . $column . ', 0) = 0';
            }
        }
        if ($this->tableHasColumn('wa_contact', 'login')) {
            $parts[] = "TRIM(COALESCE(" . $alias . ".login, '')) = ''";
        }
        return implode(' AND ', $parts);
    }

    private function validateSchema(array $options, bool $real): void
    {
        $required = array('shop_order' => array('id', 'create_datetime'),
            'shop_order_params' => array('order_id', 'name', 'value'));
        if ($options['anonymize_contacts']) {
            $required['shop_order'][] = 'contact_id';
            $required['wa_contact'] = array('id', 'name');
            foreach (array('wa_contact_emails' => array('id', 'contact_id', 'email'),
                'wa_contact_data' => array('contact_id', 'field', 'value'),
                'wa_contact_data_text' => array('contact_id', 'field', 'value'),
                'wa_contact_addresses' => array('contact_id')) as $table => $columns) {
                if ($this->tableExists($table)) {
                    $required[$table] = $columns;
                }
            }
        }
        if ($options['anonymize_order_history']) {
            $required['shop_order_log'] = array('id', 'order_id');
        }
        foreach ($required as $table => $columns) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Ошибка проверки схемы: отсутствует таблица ' . $table . '.');
            }
            foreach ($columns as $column) {
                if (!$this->tableHasColumn($table, $column)) {
                    throw new RuntimeException('Ошибка проверки схемы: отсутствует колонка ' . $table . '.' . $column . '.');
                }
            }
            $engine = $this->pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
            $engine->execute(array(':table' => $table));
            if (strtoupper((string)$engine->fetchColumn()) !== 'INNODB') {
                throw new RuntimeException('Ошибка проверки схемы: ' . $table . ' должна использовать InnoDB; автоматическое изменение движка запрещено.');
            }
        }
        $this->requireUniqueKey('shop_order', array('id'));
        $this->requireUniqueKey('shop_order_params', array('order_id', 'name'));
        if ($options['anonymize_contacts']) {
            $this->requireUniqueKey('wa_contact', array('id'));
            $this->contactOrderIndex();
            foreach (array('sex', 'birth_day', 'birth_month', 'birth_year') as $column) {
                if ($this->tableHasColumn('wa_contact', $column) && $this->columnMetadata('wa_contact')[$column]['Null'] !== 'YES') {
                    throw new RuntimeException('Ошибка проверки схемы: wa_contact.' . $column . ' не допускает NULL.');
                }
            }
            if ($this->tableExists('wa_contact_emails')) {
                $this->requireUniqueKey('wa_contact_emails', array('id'));
                $this->requireTextCapacity('wa_contact_emails', 'email', 80);
                $status = $this->tableColumnTypes('wa_contact_emails')['status'] ?? '';
                if (strpos($status, 'enum(') === 0 && strpos($status, "'unavailable'") === false) {
                    throw new RuntimeException('Ошибка проверки схемы: статус email не поддерживает unavailable.');
                }
            }
            $this->requireTextCapacity('wa_contact', 'name', 9);
            foreach (array('wa_contact_data', 'wa_contact_data_text') as $table) {
                if ($this->tableExists($table)) {
                    $this->requireTextCapacity($table, 'value', 80);
                }
            }
        }
        if ($options['wipe_comments'] && $options['anonymize_orders'] && $this->tableHasColumn('shop_order', 'comment')) {
            $this->requireTextCapacity('shop_order', 'comment', 0);
        }
        if ($options['anonymize_order_history']) {
            $this->requireUniqueKey('shop_order_log', array('id'));
            if (!$this->getOrderHistoryTextColumns()) {
                throw new RuntimeException('Ошибка проверки схемы: нет безопасных текстовых колонок истории.');
            }
            foreach ($this->getOrderHistoryTextColumns() as $column) {
                $this->requireTextCapacity('shop_order_log', $column, 31);
            }
        }
        $this->requireTextCapacity('shop_order_params', 'value', 80);
        if ($this->tableExists(self::STATE_TABLE)) {
            $this->validateStateSchema();
        }
        if ($real) {
            foreach ($required as $table => $columns) {
                $column = $columns[0];
                $this->pdo->query('EXPLAIN UPDATE ' . $this->quoteIdentifier($table) . ' SET ' .
                    $this->quoteIdentifier($column) . ' = ' . $this->quoteIdentifier($column) . ' WHERE 1 = 0');
            }
            $this->pdo->query("EXPLAIN INSERT INTO shop_order_params (order_id, name, value) VALUES (0, '_depersonalizer_probe', '')");
        }
    }

    private function validateStateSchema(): void
    {
        foreach (array('entity_type', 'entity_id', 'processed_at', 'run_id', 'note', 'payload') as $column) {
            if (!$this->tableHasColumn(self::STATE_TABLE, $column)) {
                throw new RuntimeException('Ошибка проверки схемы: отсутствует depersonalizer_state.' . $column . '.');
            }
        }
        $this->requireUniqueKey(self::STATE_TABLE, array('entity_type', 'entity_id'));
        $stmt = $this->pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute(array(':table' => self::STATE_TABLE));
        if (strtoupper((string)$stmt->fetchColumn()) !== 'INNODB') {
            throw new RuntimeException('Ошибка проверки схемы: depersonalizer_state должна использовать InnoDB.');
        }
        $this->requireTextCapacity(self::STATE_TABLE, 'run_id', 80);
    }

    private function requireTextCapacity(string $table, string $column, int $minimum): void
    {
        $type = $this->tableColumnTypes($table)[$column] ?? '';
        if (!$this->isTextColumnType($type) ||
            (preg_match('/^(?:var)?char\\((\\d+)\\)/i', $type, $match) && (int)$match[1] < $minimum)) {
            throw new RuntimeException('Ошибка проверки схемы: неподходящий тип или длина ' . $table . '.' . $column . '.');
        }
    }

    private function columnMetadata(string $table): array
    {
        if (!isset($this->columnMetadataCache[$table])) {
            $rows = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
            $this->columnMetadataCache[$table] = array_column($rows, null, 'Field');
        }
        return $this->columnMetadataCache[$table];
    }

    private function tableIndexes(string $table): array
    {
        if (!isset($this->indexCache[$table])) {
            $this->indexCache[$table] = $this->pdo->query('SHOW INDEX FROM ' . $this->quoteIdentifier($table))->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->indexCache[$table];
    }

    private function requireUniqueKey(string $table, array $columns): void
    {
        $indexes = array();
        $partial = array();
        foreach ($this->tableIndexes($table) as $row) {
            if ((int)$row['Non_unique'] === 0) {
                $indexes[$row['Key_name']][(int)$row['Seq_in_index']] = $row['Column_name'];
                if ($row['Sub_part'] !== null) {
                    $partial[$row['Key_name']] = true;
                }
            }
        }
        sort($columns);
        foreach ($indexes as $name => $indexed) {
            if (isset($partial[$name])) {
                continue;
            }
            sort($indexed);
            if ($indexed === $columns) {
                return;
            }
        }
        throw new RuntimeException('Ошибка проверки схемы: нужен уникальный ключ ' . $table . '(' . implode(',', $columns) . ').');
    }

    private function contactOrderIndex(): string
    {
        foreach ($this->tableIndexes('shop_order') as $row) {
            if ((int)$row['Seq_in_index'] === 1 && $row['Column_name'] === 'contact_id' && $row['Sub_part'] === null) {
                return (string)$row['Key_name'];
            }
        }
        throw new RuntimeException('Ошибка проверки схемы: нет индекса shop_order по contact_id; безопасная блокировка контактов невозможна.');
    }

    private function writeJsonFile(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $file = @fopen($path, 'c+b');
        if ($file === false) {
            throw new RuntimeException('Не удалось открыть журнал пакета. Изменения не разрешены.');
        }
        try {
            if (!flock($file, LOCK_EX) || !ftruncate($file, 0) || fwrite($file, $json) !== strlen($json) || !fflush($file) ||
                (function_exists('fsync') && !fsync($file))) {
                throw new RuntimeException('Не удалось записать журнал пакета.');
            }
        } finally {
            fclose($file);
        }
    }


}
