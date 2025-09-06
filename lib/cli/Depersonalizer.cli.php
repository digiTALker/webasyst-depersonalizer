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

    /**
     * Execute CLI command
     */
    public function execute()
    {
        $options = $this->parseOptions();
        $days    = (int)ifset($options['days'], $this->default_days);
        $apply   = !empty($options['apply']);
        $dry_run = !empty($options['dry-run']) || !$apply;
        $keep_geo = !empty($options['keep-geo']);
        $wipe_comments = !empty($options['wipe-comments']);
        $anonymize_contact_id = !empty($options['anonymize-contact-id']);

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $this->log("Starting depersonalization for orders before {$cutoff}. Mode: " . ($dry_run ? 'dry-run' : 'apply'));

        $order_model = new shopOrderModel();
        $orders = $order_model->select('id, contact_id')->where('create_datetime < ?', $cutoff)->fetchAll();
        $this->log("Found old orders: " . count($orders));

        if (!$dry_run) {
            $this->processOrders($orders, $keep_geo, $wipe_comments, $anonymize_contact_id);
            $this->processContacts($orders, $cutoff);
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
        $plugin = wa('shop')->getPlugin('depersonalizer');
        foreach ($orders as $o) {
            $params = $params_model->get($o['id']);
            if (ifset($params['depersonalized'], 0)) {
                continue;
            }
            foreach ($params as $k => $v) {
                if (in_array($k, array('depersonalized', 'depersonalized_at'))) {
                    continue;
                }
                $is_pii = in_array($k, $plugin->getPIIKeys()) || preg_match('/(name|email|phone|address|zip|city|region|street|house)/i', $k);
                if (!$is_pii) {
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
            $params_model->set($o['id'], 'depersonalized', 1);
            $params_model->set($o['id'], 'depersonalized_at', date('Y-m-d H:i:s'));
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
        foreach (array_keys($contact_ids) as $cid) {
            $has_new = $order_model->query("SELECT 1 FROM shop_order WHERE contact_id = i:cid AND create_datetime >= s:cutoff LIMIT 1", array('cid' => $cid, 'cutoff' => $cutoff))->fetch();
            if ($has_new) {
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
        }
    }

    /**
     * Produce anonymized value for order param
     */
    protected function maskParam($key, $value, $order_id)
    {
        switch ($key) {
            case 'email':
                return 'anon+'.$order_id.'@example.invalid';
            case 'phone':
                return 'anon-'.sha1($order_id);
            case 'firstname':
            case 'middlename':
            case 'lastname':
            case 'name':
            case 'company':
                return _wp('Удалено');
            default:
                return '';
        }
    }

    /**
     * Simple logger for CLI output
     */
    protected function log($msg)
    {
        echo date('[Y-m-d H:i:s] ') . $msg . "\n";
    }
}
