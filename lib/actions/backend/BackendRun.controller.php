<?php
/**
 * Run depersonalization batches via AJAX
 */
class shopDepersonalizerPluginBackendRunController extends waJsonController
{
    protected function preExecute()
    {
        if (!wa()->getUser()->getRights('shop', 'settings')) {
            throw new waRightsException(_w('Access denied'), 403);
        }
        $csrf = waRequest::post('_csrf', '', waRequest::TYPE_STRING);
        if (!$csrf || $csrf !== wa()->getCSRFToken()) {
            throw new waException('CSRF token invalid');
        }
    }

    public function previewAction()
    {
        $days = waRequest::post('days', 365, waRequest::TYPE_INT);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $order_model = new shopOrderModel();
        $count = (int)$order_model->select('COUNT(*)')->where('create_datetime < ?', $cutoff)->fetchField();

        $params_model = new shopOrderParamsModel();
        $rows = $params_model->query(
            "SELECT DISTINCT op.name FROM shop_order_params op JOIN shop_order o ON o.id = op.order_id WHERE o.create_datetime < s:cutoff",
            array('cutoff' => $cutoff)
        )->fetchAll(null, true);
        $params = array_fill_keys($rows, '');
        $plugin = wa('shop')->getPlugin('depersonalizer');
        $keys = $plugin->detectPIIKeys($params);
        sort($keys);

        $this->response = array(
            'message' => sprintf(_wp('%d orders will be depersonalized'), $count),
            'count'   => $count,
            'keys'    => $keys,
        );
    }

    public function runAction()
    {
        $days = waRequest::post('days', 365, waRequest::TYPE_INT);
        $keep_geo = waRequest::post('keep_geo', 0, waRequest::TYPE_INT);
        $wipe_comments = waRequest::post('wipe_comments', 0, waRequest::TYPE_INT);
        $anonymize_contact_id = waRequest::post('anonymize_contact_id', 0, waRequest::TYPE_INT);
        $offset = waRequest::post('offset', 0, waRequest::TYPE_INT);
        $limit  = waRequest::post('limit', 50, waRequest::TYPE_INT);
        $exclude_keys = waRequest::post('exclude', array());

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $order_model = new shopOrderModel();
        $total = (int)$order_model->select('COUNT(*)')->where('create_datetime < ?', $cutoff)->fetchField();

        $orders = $order_model->select('id, contact_id')
            ->where('create_datetime < ?', $cutoff)
            ->order('id')
            ->limit($limit)
            ->offset($offset)
            ->fetchAll();

        $this->processOrders($orders, $keep_geo, $wipe_comments, $anonymize_contact_id, $exclude_keys);
        $this->processContacts($orders, $cutoff);

        $processed = count($orders);
        $offset += $processed;

        $this->response = array(
            'offset'    => $offset,
            'total'     => $total,
            'processed' => $processed,
            'done'      => ($offset >= $total),
        );
        if ($this->response['done']) {
            $this->response['message'] = _wp('Depersonalization completed');
        }
    }

    protected function processOrders(array $orders, $keep_geo, $wipe_comments, $anonymize_contact_id, array $exclude_keys = array())
    {
        $params_model = new shopOrderParamsModel();
        $plugin = wa('shop')->getPlugin('depersonalizer');
        foreach ($orders as $o) {
            $params = $params_model->get($o['id']);
            if (ifset($params['depersonalized'], 0)) {
                continue;
            }
            $pii_keys = array_diff($plugin->detectPIIKeys($params), $exclude_keys);
            foreach ($pii_keys as $k) {
                if (in_array($k, array('depersonalized', 'depersonalized_at'))) {
                    continue;
                }
                if (!array_key_exists($k, $params)) {
                    continue;
                }
                $params_model->set($o['id'], $k, $this->maskParam($k, $params[$k], $o['id']));
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
}
