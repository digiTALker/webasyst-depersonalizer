<?php
/**
 * Allow administrators to download the latest depersonalization batch log.
 */
class shopDepersonalizerPluginBackendLogController extends waController
{
    public function execute()
    {
        if (!wa()->getUser()->getRights('shop', 'settings')) {
            throw new waRightsException(_w('Access denied'), 403);
        }

        $app_settings = new waAppSettingsModel();
        $path = $app_settings->get('shop', 'depersonalizer.last_log_path');
        if (!$path) {
            throw new waException(_wp('Last depersonalization log not found'), 404);
        }

        $root = wa()->getConfig()->getRootPath();
        $logs_root = $root.'/wa-log/depersonalizer/';
        if (strpos($path, $logs_root) !== 0 || !file_exists($path) || !is_readable($path)) {
            throw new waException(_wp('Last depersonalization log not found'), 404);
        }

        waFiles::readFile($path, basename($path), true);
        exit;
    }
}
