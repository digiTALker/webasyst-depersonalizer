<?php
/**
 * CLI entry point for depersonalizing orders and contacts
 */
class shopDepersonalizerCli extends waCliController
{
    /**
     * Default retention period
     */
    protected $default_days = 365;

    protected $processed_orders = array();
    protected $skipped_orders = array();
    protected $processed_contacts = array();
    protected $skipped_contacts = array();

    /**
     * Execute CLI command
     */
    public function execute()
    {
        $options = $this->parseOptions();

        $plugin = wa('shop')->getPlugin('depersonalizer');
        $settings = $plugin ? (array)$plugin->getSettings() : array();

        $days = (int)ifset($options['days'], (int)ifset($settings['retention_days'], $this->default_days));
        $apply = !empty($options['apply']);
        $dry_run = !empty($options['dry-run']) || !$apply;
        $keep_geo = isset($options['keep-geo']) ? (bool)$options['keep-geo'] : !empty($settings['keep_geo']);
        $wipe_comments = isset($options['wipe-comments']) ? (bool)$options['wipe-comments'] : !empty($settings['wipe_comments']);
        $anonymize_contact_id = isset($options['anonymize-contact-id']) ? (bool)$options['anonymize-contact-id'] : !empty($settings['anonymize_contact_id']);

        $settings_model = new waAppSettingsModel();

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->log("Starting depersonalization for orders before {$cutoff}. Mode: " . ($dry_run ? 'dry-run' : 'apply'));

        $order_model = new shopOrderModel();
        $total = (int)$order_model
            ->query(
                "SELECT COUNT(*) cnt FROM shop_order WHERE create_datetime < s:cutoff",
                array('cutoff' => $cutoff)
            )
            ->fetchField();
        $this->log("Found old orders: " . $total);

        if ($dry_run) {
            $this->log('Dry-run mode. No data will be modified.');
        } else {
            $limit = 500;
            $offset = 0;
            $processed = 0;
            while (true) {
                $orders = $order_model
                    ->query(
                        "SELECT id, contact_id FROM shop_order WHERE create_datetime < s:cutoff ORDER BY id LIMIT i:limit OFFSET i:offset",
                        array(
                            'cutoff' => $cutoff,
                            'limit'  => $limit,
                            'offset' => $offset,
                        )
                    )
                    ->fetchAll();

                if (!$orders) {
                    break;
                }

                $this->processOrders($orders, $keep_geo, $wipe_comments, $anonymize_contact_id);
                $this->processContacts($orders, $cutoff);

                $processed += count($orders);
                $this->log("Processed {$processed}/{$total}");

                $offset += $limit;
                unset($orders);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $plugin = wa('shop')->getPlugin('depersonalizer');
            if ($plugin && method_exists($plugin, 'logBatch')) {
                $path = $plugin->logBatch([
                    'orders'   => ['processed' => $this->processed_orders, 'skipped' => $this->skipped_orders],
                    'contacts' => ['processed' => $this->processed_contacts, 'skipped' => $this->skipped_contacts],
                ]);
                $this->log('Batch details saved to '.$path);
            }

            $settings_model->set('shop', 'depersonalizer.retention_days', $days);
            $settings_model->set('shop', 'depersonalizer.keep_geo', (int)$keep_geo);
            $settings_model->set('shop', 'depersonalizer.wipe_comments', (int)$wipe_comments);
            $settings_model->set('shop', 'depersonalizer.anonymize_contact_id', (int)$anonymize_contact_id);
            $settings_model->set('shop', 'depersonalizer.last_run_at', date('Y-m-d H:i:s'));
        }

        $this->log('Done');
    }

    /**
     * Parse command line options into array
     *
     * @return array
     */
    protected function parseOptions()
    {
        $result = array();
        $argv = waRequest::server('argv', array());
        foreach ($argv as $arg) {
            if (substr($arg, 0, 2) !== '--') {
                continue;
            }
            $arg = substr($arg, 2);
            if (strpos($arg, '=') !== false) {
                list($name, $value) = explode('=', $arg, 2);
            } else {
                $name = $arg;
                $value = true;
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * Depersonalize order parameters
     */
    protected function processOrders(array $orders, $keep_geo, $wipe_comments, $anonymize_contact_id)
    {
        $params_model = new shopOrderParamsModel();
        $order_model  = new shopOrderModel();
        $plugin       = wa('shop')->getPlugin('depersonalizer');
        $anon_cid     = null;
        if ($anonymize_contact_id) {
            $anon_cid = $plugin->getAnonContactId();
        }
        foreach ($orders as $o) {
            $params = $params_model->get($o['id']);
            if (ifset($params['depersonalized'], 0)) {
                $this->skipped_orders[$o['id']] = 'already_depersonalized';
                continue;
            }

            if ($keep_geo) {
                foreach (array('country', 'region', 'city') as $gk) {
                    if (isset($params[$gk])) {
                        $params_model->set($o['id'], 'geo_' . $gk, $params[$gk]);
                    }
                }
            }

            foreach ($params as $k => $v) {
                if (in_array($k, array('depersonalized', 'depersonalized_at'))) {
                    continue;
                }
                if (!$plugin->isPIIKey($k)) {
                    continue;
                }
                $params_model->set($o['id'], $k, $this->maskParam($k, $v, $o['id']));
            }

            if ($wipe_comments) {
                foreach (array('comment', 'customer_comment') as $c_key) {
                    if (isset($params[$c_key])) {
                        $params_model->set($o['id'], $c_key, '');
                    }
                }
            }

            if ($anonymize_contact_id && !empty($o['contact_id'])) {
                $order_model->updateById($o['id'], array('contact_id' => $anon_cid));
            }

            $params_model->set($o['id'], 'depersonalized', 1);
            $params_model->set($o['id'], 'depersonalized_at', date('Y-m-d H:i:s'));
            $this->processed_orders[] = $o['id'];
        }
    }

    /**
     * Depersonalize contacts related to orders
     */
    protected function processContacts(array $orders, $cutoff)
    {
        $contact_ids = array();
        foreach ($orders as $o) {
            if (!empty($o['contact_id'])) {
                $contact_ids[$o['contact_id']] = true;
            }
        }
        if (!$contact_ids) {
            return;
        }
        $order_model = new shopOrderModel();
        $contact_model = new waContactModel();
        $email_model = new waContactEmailsModel();
        $phone_model = new waContactDataModel();
        $addr_model = new waContactAddressesModel();
        $param_model = new waContactParamsModel();
        $plugin = wa('shop')->getPlugin('depersonalizer');
        foreach (array_keys($contact_ids) as $cid) {
            $has_new = $order_model->query("SELECT 1 FROM shop_order WHERE contact_id = i:cid AND create_datetime >= s:cutoff LIMIT 1", array('cid' => $cid, 'cutoff' => $cutoff))->fetch();
            if ($has_new) {
                $this->skipped_contacts[$cid] = 'has_newer_orders';
                continue;
            }
            $is_depersonalized = $param_model->query(
                "SELECT 1 FROM wa_contact_params WHERE contact_id = i:cid AND name = 'depersonalized' AND value = 1 LIMIT 1",
                array('cid' => $cid)
            )->fetch();
            if ($is_depersonalized) {
                $plugin->log(sprintf('Skipping contact %d: already depersonalized', $cid));
                continue;
            }
            $contact_model->updateById($cid, array(
                'firstname'  => _wp('Удалено'),
                'middlename' => '',
                'lastname'   => _wp('Удалено'),
            ));
            $email_model->updateByField('contact_id', $cid, array('email' => 'anon+'.$cid.'@example.invalid'));
            $phone_model->updateByField(array('contact_id' => $cid, 'field' => 'phone'), array('value' => 'anon-'.sha1($cid)));
            $addr_model->deleteByField('contact_id', $cid);
            $param_model->set($cid, 'depersonalized', 1);
            $param_model->set($cid, 'depersonalized_at', date('Y-m-d H:i:s'));
            $this->processed_contacts[] = $cid;
        }
    }

    /**
     * Produce anonymized value for order param
     */
    protected function maskParam($key, $value, $order_id)
    {
        if (preg_match('/email/i', $key)) {
            return 'anon+'.$order_id.'@example.invalid';
        }
        if (preg_match('/phone/i', $key)) {
            return 'anon-'.sha1($order_id);
        }
        if (preg_match('/(firstname|middlename|lastname|name|company)/i', $key)) {
            return _wp('Удалено');
        }
        if (preg_match('/ip/i', $key)) {
            return '0.0.0.0';
        }
        if (preg_match('/user_agent/i', $key)) {
            return 'unknown';
        }
        return '';
    }

    /**
     * Simple logger for CLI output
     */
    protected function log($msg)
    {
        // use plugin logger to persist messages
        try {
            $plugin = wa('shop')->getPlugin('depersonalizer');
            if ($plugin && method_exists($plugin, 'log')) {
                $plugin->log($msg);
            }
        } catch (Exception $e) {
            // ignore logging errors but still output to console
        }

        echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    }
}
