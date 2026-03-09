<?php
/**
 * Main plugin class providing helpers and menu binding.
 */
class shopDepersonalizerPlugin extends shopPlugin
{
    /**
     * Log file name located in wa-log/
     */
    const LOG_FILE = 'depersonalizer.log';

    /**
     * Registry of known PII keys in shop_order_params
     * @var array
     */
    protected $pii_keys = array(
        'firstname', 'middlename', 'lastname', 'name', 'company',
        'email', 'phone',
        'address', 'zip', 'city', 'region', 'country', 'street', 'house',
        'customer_comment', 'comment', 'ip', 'user_agent',
        'shipping_*', 'billing_*', 'utm_*'
    );

    /**
     * Determine if given key contains PII
     *
     * @param string $key
     * @return bool
     */
    public function isPIIKey($key)
    {
        foreach ($this->pii_keys as $p) {
            if ($p === $key) {
                return true;
            }
            if (substr($p, -1) === '*' && strpos($key, rtrim($p, '*')) === 0) {
                return true;
            }
        }
        return (bool)preg_match('/(name|email|phone|address|zip|city|region|street|house)/i', $key);
    }

    /**
     * Detect PII keys within given params combining with static list.
     *
     * @param array $params
     * @return array
     */
    public function detectPIIKeys($params)
    {
        $detected = array();
        foreach ($params as $k => $v) {
            if ($this->isPIIKey($k)) {
                $detected[] = $k;
            }
        }
        return array_values(array_unique($detected));
    }

    /**
     * Return list of PII keys. Allows extensions via hook.
     *
     * @return array
     */
    public function getPIIKeys()
    {
        return $this->pii_keys;
    }

    /**
     * Return ID of anonymous contact, creating one if needed.
     *
     * @return int
     */
    public function getAnonContactId()
    {
        $app_settings_model = new waAppSettingsModel();
        $cid = $app_settings_model->get('shop', 'depersonalizer.anon_contact_id');
        if ($cid) {
            return (int)$cid;
        }
        $contact = new waContact();
        $contact['firstname'] = _wp('Anonymous');
        $contact['lastname']  = _wp('Customer');
        $contact->save();
        $cid = $contact->getId();
        $app_settings_model->set('shop', 'depersonalizer.anon_contact_id', $cid);
        return (int)$cid;
    }

    /**
     * Write message to plugin log
     *
     * @param string $message
     */
    public function log($message)
    {
        waLog::log($message, self::LOG_FILE);
    }

    /**
     * Save batch processing details to dated JSON log file.
     *
     * @param array $data Arbitrary batch information
     * @return string Absolute path to created log file
     */
    public function logBatch(array $data)
    {
        $root = wa()->getConfig()->getRootPath();
        $dir  = $root.'/wa-log/depersonalizer/'.date('Y-m-d');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir.'/batch-'.date('H-i-s').'.json';
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($file, $json);

        // keep reference to log file in main plugin log
        waLog::log('Batch log created: '.$file, self::LOG_FILE);

        // remember path to last log file in settings
        $settings_model = new waAppSettingsModel();
        $settings_model->set('shop', 'depersonalizer.last_log_path', $file);

        return $file;
    }

    /**
     * Add plugin link to backend menu
     *
     * @param array $menu
     * @return array
     */
    public function backendMenu(&$menu)
    {
        $menu['plugins']['items'][] = array(
            'url'  => '?plugin=depersonalizer&action=list',
            'name' => _wp('AnonGuard')
        );
        return $menu;
    }
}
