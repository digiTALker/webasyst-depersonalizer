<?php
/**
 * Plugin uninstall script
 */
class shopDepersonalizerPluginUninstall
{
    public static function uninstall()
    {
        $app_settings_model = new waAppSettingsModel();
        $keys = array(
            'depersonalizer.retention_days',
            'depersonalizer.keep_geo',
            'depersonalizer.wipe_comments',
            'depersonalizer.anonymize_contact_id',
            'depersonalizer.anon_contact_id',
            'depersonalizer.last_run_at',
            'depersonalizer.last_log_path',
        );
        foreach ($keys as $key) {
            $app_settings_model->del('shop', $key);
        }
    }
}
