<?php

use \Blackfire\HttpService;

class AdminBlackfireSyncController extends ModuleAdminController 
{
    protected $categoryID = false;
    protected $subcategoryID = false;

    public function __construct() 
    {
        parent::__construct();
    }

    public function init()
    {
        parent::init();
        $this->bootstrap = true;
        $this->categoryID = Tools::getValue("category_id");
        $this->subcategoryID = Tools::getValue("subcategory_id");

        $user = Configuration::get('BLACKFIRESYNC_ACCOUNT_EMAIL', null);
        $password = Configuration::get('BLACKFIRESYNC_ACCOUNT_PASSWORD', null);

        $this->blackfireService = new HttpService($user, $password);
    }

    public function initContent()
    {
        $assigns = array(
            'categoryID' => $this->categoryID,
            'subcategoryID' => $this->subcategoryID,
            'account' => $this->blackfireService->getAccountInfo(),
            'categories' => $this->blackfireService->getCategories(),
            'products' => $this->blackfireService->getProducts($this->subcategoryID),
        );

        parent::initContent();
        $this->context->smarty->assign($assigns);
        $this->setTemplate('blackfire_sync.tpl');
    }
}