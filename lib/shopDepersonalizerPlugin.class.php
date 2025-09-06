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
