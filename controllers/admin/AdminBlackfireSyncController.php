<?php

use \Blackfire\BlackfireSyncService;

class AdminBlackfireSyncController extends ModuleAdminController 
{
    protected $categoryID = false;
    protected $subcategoryID = false;
    protected $created_shop_product = false;

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
        if ($this->action == "new") $this->newShopProduct();
        if ($this->action == "force") $this->changeIgnoreDeadline();
    }

    public function initContent()
    {
        $assigns = array(
            'categoryID' => $this->categoryID,
            'subcategoryID' => $this->subcategoryID,
            'account' => BlackfireSyncService::getAccountInfo(),
            'categories' => BlackfireSyncService::getCategories(),
            'products' => BlackfireSyncService::getProducts($this->categoryID, $this->subcategoryID),
        );

        parent::initContent();
        $this->context->smarty->assign($assigns);
        $this->setTemplate('blackfire_sync.tpl');
    }

    public function postProcess() {
        if ($this->created_shop_product) {
            $this->redirect_after = Context::getContext()->link->getAdminLink('AdminProducts', true, ['id_product' => $this->created_shop_product->id]);
        }
        parent::postProcess();
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

    protected function newShopProduct()
    {
        $id_product = Tools::getValue("id_product");

        $shop_product = BlackfireSyncService::createShopProduct($id_product, $this->subcategoryID);

        $this->created_shop_product = $shop_product;
    }

    protected function changeIgnoreDeadline()
    {
        $id_product = Tools::getValue("id_product");
        $ignore_deadline = Tools::getValue("ignore_deadline");
        BlackfireSyncService::changeIgnoreDeadline($id_product, $ignore_deadline);
    }
}