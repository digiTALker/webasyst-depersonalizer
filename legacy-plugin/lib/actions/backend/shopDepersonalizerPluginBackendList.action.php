<?php
/**
 * Backend placeholder action.
 */
class shopDepersonalizerPluginBackendListAction extends waViewAction
{
    public function execute()
    {
        // Only users with shop settings rights may run depersonalization
        if (!wa()->getUser()->getRights('shop', 'settings')) {
            throw new waRightsException(_w('Access denied'));
        }

        $plugin   = wa('shop')->getPlugin('depersonalizer');
        $settings = $plugin->getSettings();

        // Build form controls for template
        $fields = array(
            'days' => waHtmlControl::getControl(waHtmlControl::INPUT, array(
                'name'  => 'days',
                'id'    => 'days',
                'value' => (int) ifset($settings['retention_days'], 365),
            )),
            'keep_geo' => waHtmlControl::getControl(waHtmlControl::CHECKBOX, array(
                'name'    => 'keep_geo',
                'id'      => 'keep_geo',
                'value'   => 1,
                'checked' => !empty($settings['keep_geo']),
            )),
            'wipe_comments' => waHtmlControl::getControl(waHtmlControl::CHECKBOX, array(
                'name'    => 'wipe_comments',
                'id'      => 'wipe_comments',
                'value'   => 1,
                'checked' => !empty($settings['wipe_comments']),
            )),
            'anonymize_contact_id' => waHtmlControl::getControl(waHtmlControl::CHECKBOX, array(
                'name'    => 'anonymize_contact_id',
                'id'      => 'anonymize_contact_id',
                'value'   => 1,
                'checked' => !empty($settings['anonymize_contact_id']),
            )),
        );

        $this->view->assign(array(
            'settings' => $settings,
            'fields'   => $fields,
            'message'  => _wp('Configure depersonalization and run below.'),
        ));
    }
}
