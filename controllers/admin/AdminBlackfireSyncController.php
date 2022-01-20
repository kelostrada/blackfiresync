<?php

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
    }

    public function initContent()
    {
        $assigns = array(
            'test' => [1,2,3]
        );

        parent::initContent();
        $this->context->smarty->assign($assigns);
        $this->setTemplate('blackfire_sync.tpl');
    }
}