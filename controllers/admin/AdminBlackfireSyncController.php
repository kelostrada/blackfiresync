<?php

use \Blackfire\BlackfireSyncService;

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
        $this->action = Tools::getValue("action");

        if ($this->action == "ok") $this->saveShopProduct();
        if ($this->action == "x") $this->deleteShopProduct();
    }

    public function initContent()
    {
        $assigns = array(
            'categoryID' => $this->categoryID,
            'subcategoryID' => $this->subcategoryID,
            'account' => BlackfireSyncService::getAccountInfo(),
            'categories' => BlackfireSyncService::getCategories(),
            'products' => BlackfireSyncService::getProducts($this->subcategoryID),
        );

        parent::initContent();
        $this->context->smarty->assign($assigns);
        $this->setTemplate('blackfire_sync.tpl');
    }

    protected function saveShopProduct()
    {
        $id_shop_product = Tools::getValue("id_shop_product");
        $id_product = Tools::getValue("id_product");

        BlackfireSyncService::setShopProduct($id_product, $id_shop_product, $this->subcategoryID);
    }

    protected function deleteShopProduct()
    {
        $id_product = Tools::getValue("id_product");

        BlackfireSyncService::cleanShopProduct($id_product);
    }
}