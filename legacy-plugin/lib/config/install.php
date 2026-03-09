<?php
/**
 * Plugin installation script
 */
class shopDepersonalizerPluginInstall
{
    public static function install()
    {
        $app_settings_model = new waAppSettingsModel();

        $icon_path = wa()->getAppPath('plugins/depersonalizer/img/icon.png', 'shop');
        $base64_path = wa()->getAppPath('plugins/depersonalizer/img/icon.png.base64', 'shop');
        if (!file_exists($icon_path) && file_exists($base64_path)) {
            $data = file_get_contents($base64_path);
            file_put_contents($icon_path, base64_decode($data));
        }

        $defaults = array(
            'depersonalizer.retention_days'     => 365,
            'depersonalizer.keep_geo'           => 0,
            'depersonalizer.wipe_comments'      => 0,
            'depersonalizer.anonymize_contact_id' => 0,
            'depersonalizer.anon_contact_id'    => null,
            'depersonalizer.last_run_at'        => null,
            'depersonalizer.last_log_path'      => null,
        );
        foreach ($defaults as $key => $value) {
            if ($app_settings_model->get('shop', $key) === null) {
                $app_settings_model->set('shop', $key, $value);
            }
        }
    }
}
