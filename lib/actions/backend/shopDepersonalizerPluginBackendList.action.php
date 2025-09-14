<?php
/**
 * Backend placeholder action.
 */
class shopDepersonalizerPluginBackendListAction extends waViewAction
{
    public function execute()
    {
        $plugin = wa('shop')->getPlugin('depersonalizer');
        $settings = $plugin->getSettings();
        $this->view->assign(array(
            'settings' => $settings,
            'message'  => _wp('Configure depersonalization and run below.'),
        ));
    }
}
