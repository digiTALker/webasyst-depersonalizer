<?php
/**
 * Run depersonalization batches via AJAX
 */
class shopDepersonalizerPluginBackendRunController extends waJsonController
{
    protected function preExecute()
    {
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
        $this->response = array(
            'message' => sprintf(_wp('%d orders will be depersonalized'), $count),
            'count'   => $count,
        );
    }

    public function runAction()
    {
        $days = waRequest::post('days', 365, waRequest::TYPE_INT);
        $keep_geo = waRequest::post('keep_geo', 0, waRequest::TYPE_INT);
        $wipe_comments = waRequest::post('wipe_comments', 0, waRequest::TYPE_INT);
        $anonymize_contact_id = waRequest::post('anonymize_contact_id', 0, waRequest::TYPE_INT);

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $order_model = new shopOrderModel();
        $orders = $order_model->select('id, contact_id')->where('create_datetime < ?', $cutoff)->fetchAll();

        $this->processOrders($orders, $keep_geo, $wipe_comments, $anonymize_contact_id);
        $this->processContacts($orders, $cutoff);

        $this->response = array(
            'message'   => _wp('Depersonalization completed'),
            'processed' => count($orders),
        );
    }

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
