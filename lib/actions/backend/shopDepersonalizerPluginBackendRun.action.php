<?php
/**
 * Render progress page and kick off depersonalization.
 */
class shopDepersonalizerPluginBackendRunAction extends waViewAction
{
    public function execute()
    {
        // Rights and CSRF checks
        if (!wa()->getUser()->getRights('shop', 'settings')) {
            throw new waRightsException(_w('Access denied'));
        }
        $csrf = waRequest::post('_csrf', '', waRequest::TYPE_STRING);
        if (!$csrf || $csrf !== wa()->getCSRFToken()) {
            throw new waException('CSRF token invalid');
        }

        $plugin = wa('shop')->getPlugin('depersonalizer');

        $keys = waRequest::post('keys', array(), waRequest::TYPE_ARRAY_TRIM);
        $keys = array_values(array_unique(array_filter($keys, 'strlen')));
        if ($keys) {
            $keys = array_values(array_filter($keys, array($plugin, 'isPIIKey')));
        }

        $keys_selected = waRequest::post('keys_selected', 0, waRequest::TYPE_INT) ? 1 : 0;

        $options = array(
            'days'                 => waRequest::post('days', 365, waRequest::TYPE_INT),
            'keep_geo'             => waRequest::post('keep_geo', 0, waRequest::TYPE_INT),
            'wipe_comments'        => waRequest::post('wipe_comments', 0, waRequest::TYPE_INT),
            'anonymize_contact_id' => waRequest::post('anonymize_contact_id', 0, waRequest::TYPE_INT),
            'keys'                 => $keys,
            'keys_selected'        => $keys_selected,
            '_csrf'                => $csrf,
        );
        $settings_model = new waAppSettingsModel();
        $settings_model->set('shop', 'depersonalizer.retention_days', $options['days']);
        $settings_model->set('shop', 'depersonalizer.keep_geo', (int)$options['keep_geo']);
        $settings_model->set('shop', 'depersonalizer.wipe_comments', (int)$options['wipe_comments']);
        $settings_model->set('shop', 'depersonalizer.anonymize_contact_id', (int)$options['anonymize_contact_id']);
        $settings_model->set('shop', 'depersonalizer.last_run_at', date('Y-m-d H:i:s'));

        $this->view->assign('options', $options);
    }
}
