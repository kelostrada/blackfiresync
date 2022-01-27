<?php

namespace Blackfire;

use Blackfire\HttpService;
use \Db;
use \Context;
use \Shop;

class BlackfireSyncService
{
    private Context $context;
    private HttpService $httpService;

    public static function login($user, $password)
    {
        static::getInstance()->httpService = new HttpService($user, $password);
    }

    public static function getAccountInfo()
    {
        return static::getInstance()->httpService->getAccountInfo();
    }

    public static function getCategories()
    {
        return static::getInstance()->httpService->getCategories();
    }

    public static function getProducts($subcategoryID)
    {
        return static::getInstance()->getProductsFromSubcategory($subcategoryID);
    }

    public static function setShopProduct($id_product, $id_shop_product)
    {
        $res = DB::getInstance()->delete('blackfiresync_products', 'id = ' . $id_product);
        DB::getInstance()->execute('INSERT INTO `'._DB_PREFIX_.'blackfiresync_products` 
            (`id`, `id_shop_product`) VALUES ('.$id_product.','.$id_shop_product.')');
    }

    public function getProductsFromSubcategory($subcategoryID)
    {
        $bfproducts = $this->httpService->getProducts($subcategoryID);
        $bfproduct_ids = array_map(function($bfp) { return $bfp['ID']; }, $bfproducts);
        $bfproduct_ids = implode(',', $bfproduct_ids);

        $shop_products = DB::getInstance()->executeS('SELECT bfsp.id as blackfire_id, p.id_product, pl.name, pl.link_rewrite, img.id_image
            FROM `'._DB_PREFIX_.'blackfiresync_products` bfsp
            JOIN `'._DB_PREFIX_.'product` p ON bfsp.`id_shop_product` = p.`id_product`
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (p.`id_product` = pl.`id_product` ' . Shop::addSqlRestrictionOnLang('pl') . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` img ON img.`id_product` = p.`id_product` AND img.cover=1 AND img.id_shop=' . (int) $this->context->shop->id . '
            WHERE pl.`id_lang` = ' . $this->context->language->id . '
            AND bfsp.id IN ('.$bfproduct_ids.')');

        // dump($shop_products);

        foreach($bfproducts as &$bfp)
        {
            $shop_product = current(array_filter($shop_products, fn($p) => $p['blackfire_id'] == $bfp['ID']));
            $bfp['shop_product'] = $shop_product;
        }

        return $bfproducts;
    }

    private static $instances = [];

    protected function __construct() 
    { 
        $this->context = Context::getContext();
    }

    protected function __clone() { }

    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    public static function getInstance(): BlackfireSyncService
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }
}