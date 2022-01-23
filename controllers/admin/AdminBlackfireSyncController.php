<?php

use \Blackfire\HttpService;

class AdminBlackfireSyncController extends ModuleAdminController 
{
    public function __construct() 
    {
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        $this->bootstrap = true;

        $user = Configuration::get('BLACKFIRESYNC_ACCOUNT_EMAIL', null);
        $password = Configuration::get('BLACKFIRESYNC_ACCOUNT_PASSWORD', null);

        $this->blackfireService = new HttpService($user, $password);
    }

    public function initContent()
    {
        $assigns = array(
            'account' => $this->blackfireService->getAccountInfo()
        );

        parent::initContent();
        $this->context->smarty->assign($assigns);
        $this->setTemplate('blackfire_sync.tpl');
    }
}