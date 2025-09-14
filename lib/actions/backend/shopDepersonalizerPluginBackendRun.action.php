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

        $options = array(
            'days'                 => waRequest::post('days', 365, waRequest::TYPE_INT),
            'keep_geo'             => waRequest::post('keep_geo', 0, waRequest::TYPE_INT),
            'wipe_comments'        => waRequest::post('wipe_comments', 0, waRequest::TYPE_INT),
            'anonymize_contact_id' => waRequest::post('anonymize_contact_id', 0, waRequest::TYPE_INT),
            '_csrf'                => $csrf,
        );

        $this->view->assign('options', $options);
    }
}
