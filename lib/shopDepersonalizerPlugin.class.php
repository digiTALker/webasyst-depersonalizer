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
        'email', 'phone', 'shipping_address', 'billing_address',
        'address', 'zip', 'city', 'region', 'street', 'house',
        'customer_comment', 'comment', 'ip', 'user_agent'
    );

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
