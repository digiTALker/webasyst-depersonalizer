<?php
/**
 * Backend placeholder action.
 */
class shopDepersonalizerPluginBackendListAction extends waViewAction
{
    public function execute()
    {
        $this->view->assign('message', _wp('AnonGuard plugin is installed. CLI usage only in this version.'));
    }
}
