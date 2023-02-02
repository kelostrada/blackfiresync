<?php

namespace Blackfire;

use Configuration;
use Context;
use DateTime;
use Db;
use Image;
use Language;
use Manufacturer;
use Product;
use Shop;
use TaxRulesGroup;

use Blackfire\HttpService;
use Blackfire\ImageHelpers;
use PrestaShop\PrestaShop\Core\Domain\Product\Stock\ValueObject\OutOfStockType;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function findIndex($a,$f){foreach($a as $k=>$v)if($f($v,$k,$a))return $k;}

class BlackfireSyncService
{
    private Context $context;
    private HttpService $httpService;

    public static function getAccountInfo()
    {
        return static::getInstance()->httpService->getAccountInfo();
    }

    public static function getCategories()
    {
        return static::getInstance()->getLocalCategories();
    }

    public static function getProducts($categoryID, $subcategoryID)
    {
        return static::getInstance()->getProductsFromSubcategory($categoryID, $subcategoryID);
    }

    public static function setShopProduct($id_product, $id_shop_product, $categoryID, $subcategoryID)
    {
        return static::getInstance()->updateShopProduct($id_product, $id_shop_product, $categoryID, $subcategoryID);
    }

    public static function cleanShopProduct($id_product)
    {
        return static::getInstance()->deleteShopProduct($id_product);
    }

    public static function createShopProduct($id_product, $categoryID, $subcategoryID)
    {
        return static::getInstance()->newShopProduct($id_product, $categoryID, $subcategoryID);
    }

    public static function syncProducts()
    {
        return static::getInstance()->sync();
    }

    public static function syncCategories()
    {
        return static::getInstance()->syncCategoriesLocally();
    }

    public static function changeIgnoreDeadline($id_product, $ignore_deadline)
    {
        return static::getInstance()->modifyIgnoreDeadline($id_product, $ignore_deadline);
    }

    public function getProductsFromSubcategory($categoryID, $subcategoryID)
    {
        $bfproducts = $this->httpService->getProducts($categoryID, $subcategoryID);
        $bfproduct_ids = array_map(function($bfp) { return $bfp['id']; }, $bfproducts);
        $bfproduct_ids = array_filter($bfproduct_ids);
        $bfproduct_ids = implode(',', $bfproduct_ids);

        $query = 'SELECT bfsp.id as blackfire_id, bfsp.ignore_deadline, p.id_product, pl.name, pl.link_rewrite, img.id_image
            FROM `'._DB_PREFIX_.'blackfiresync_products` bfsp
            JOIN `'._DB_PREFIX_.'product` p ON bfsp.`id_shop_product` = p.`id_product`
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON (p.`id_product` = pl.`id_product` ' . Shop::addSqlRestrictionOnLang('pl') . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'image_shop` img ON img.`id_product` = p.`id_product` AND img.cover=1 AND img.id_shop=' . (int) $this->context->shop->id . '
            WHERE pl.`id_lang` = ' . $this->context->language->id;

        if ($bfproduct_ids != '')
        {
            $query .= ' AND bfsp.id IN ('.$bfproduct_ids.')';
        }

        $shop_products = DB::getInstance()->executeS($query);

        foreach($bfproducts as &$bfp)
        {
            $shop_product = current(array_filter($shop_products, fn($p) => $p['blackfire_id'] == $bfp['id']));
            $bfp['shop_product'] = $shop_product;
        }

        return $bfproducts;
    }

    public function getLocalCategories()
    {
        $query = 'SELECT c.* FROM `'._DB_PREFIX_.'blackfiresync_categories` c';
        $categories = DB::getInstance()->executeS($query);

        $query = 'SELECT sc.* FROM `'._DB_PREFIX_.'blackfiresync_subcategories` sc';
        $subcategories = DB::getInstance()->executeS($query);

        $result = [];

        foreach ($subcategories as $subcategory)
        {
            $index = findIndex($categories, function($w,$i,$a) use($subcategory) { return $w['id'] == $subcategory['category_id'];});

            if (!isset($result[$subcategory['category_id']])) 
            {
                $result[$subcategory['category_id']] = $categories[$index];
            }
            $result[$subcategory['category_id']]['subcategories'][$subcategory['id']] = $subcategory;
        }

        return $result;
    }

    public function updateShopProduct($id_product, $id_shop_product, $categoryID, $subcategoryID)
    {
        $result_delete = DB::getInstance()->delete('blackfiresync_products', 'id = ' . $id_product);
        $result_insert = DB::getInstance()->execute('INSERT INTO `'._DB_PREFIX_.'blackfiresync_products` 
            (`id`, `id_shop_product`, `subcategory_id`) VALUES ('.$id_product.','.$id_shop_product.','.$subcategoryID.')');

        if ($result_delete && $result_insert)
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::updateShopProduct | product: ' . $id_product . ' | subcategory: ' . $subcategoryID, 1, null, 'Product', $id_shop_product);
        }
        else
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::updateShopProduct | product: ' . $id_product . ' | subcategory: ' . $subcategoryID, 3, null, 'Product', $id_shop_product);
            $this->logger->error('updateShopProduct() ' . $id_product . ' ' . $id_shop_product, [
                'result_delete' => $result_delete,
                'result_insert' => $result_insert,
                'id_product' => $id_product,
                'id_shop_product' => $id_shop_product,
                'id_category' => $subcategoryID
            ]);
        }
    }

    public function deleteShopProduct($id_product)
    {
        $result_delete = DB::getInstance()->delete('blackfiresync_products', 'id = ' . $id_product);

        if ($result_delete)
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::deleteShopProduct | product: ' . $id_product, 1, null, 'Product');
        }
        else
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::deleteShopProduct | product: ' . $id_product, 3, null, 'Product');
            $this->logger->error('deleteShopProduct() ' . $id_product, [
                'result_delete' => $result_delete,
                'id_product' => $id_product,
            ]);
        }
    }

    public function newShopProduct($blackfireProductID, $categoryID, $subcategoryID)
    {
        $products = $this->httpService->getProducts($categoryID, $subcategoryID);
        $product = current(array_filter($products, fn($p) => $p['id'] == $blackfireProductID));
        $product_details = $this->httpService->getProduct($blackfireProductID);

        $price = floatval($product['price']) * 5 * 1.3 * 1.23;
        $price = ceil( $price / 5 ) * 5 - 0.05;
        $price = strval(round($price / 1.23, 6));
        
        $shop_product = new Product();
        $shop_product->name = [];

        $languages = Language::getLanguages();

        foreach($languages as $language)
        {
            $shop_product->name[$language['id_lang']] = str_replace(array('#'), '', html_entity_decode($product_details['name'], ENT_QUOTES));
            $shop_product->description[$language['id_lang']] = $product_details['description'];
        }

        $shop_product->reference = $product['ref'];

        // VAT 23%
        $shop_product->price = $price;
        $shop_product->id_tax_rules_group = TaxRulesGroup::getIdByName('PL Standard Rate (23%)');

        $shop_product->wholesale_price = floatval($product['price']) * 4.7;

        $shop_product->ean13 =  ltrim(preg_replace('/[^0-9]/s','',$product['ean']), '0');
        $shop_product->active = false;
        $shop_product->weight = round(0.003 * $price, 3);

        $shop_product->id_manufacturer = Manufacturer::getIdByName($product_details['manufacturer']);
        $shop_product->manufacturer_name = $product_details['manufacturer'];

        if ($shop_product->add())
        {
            $result = $this->updateShopProduct($blackfireProductID, $shop_product->id, $categoryID, $subcategoryID);
            $this->syncProduct($product, $blackfireProductID, $shop_product->id, false);

            $image = new Image();
            $image->id_product = $shop_product->id;
            $image->cover = true;
            if ($image->add()) {
                if (!ImageHelpers::copyImg($shop_product->id, $image->id, $product_details['image'], 'products', false)) {
                    $image->delete();
                }
            }

            return $shop_product;
        }

        return false;
    }

    public function modifyIgnoreDeadline($id_product, $ignore_deadline)
    {
        $ignore_deadline = $ignore_deadline == "on" ? 1 : 0;

        $result = DB::getInstance()->execute('UPDATE `'._DB_PREFIX_.'blackfiresync_products`    
            SET `ignore_deadline` = ' . $ignore_deadline . ' WHERE `id` = ' . $id_product);

        if ($result)
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::modifyIgnoreDeadline', 1, null, 'BlackfireProduct', $id_product);
        }
        else
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::modifyIgnoreDeadline', 3, null, 'BlackfireProduct', $id_product);
            $this->logger->error('modifyIgnoreDeadline() ' . $id_product, [
                'result' => $result,
                'id_product' => $id_product,
                'ignore_deadline' => $ignore_deadline
            ]);
        }
    }

    public function sync()
    {
        $shop_products = DB::getInstance()->executeS('SELECT bfsp.*, p.*, bfssc.category_id
            FROM `'._DB_PREFIX_.'blackfiresync_products` bfsp
            JOIN `'._DB_PREFIX_.'product` p ON bfsp.`id_shop_product` = p.`id_product`
            JOIN `'._DB_PREFIX_.'blackfiresync_subcategories` bfssc ON bfsp.subcategory_id = bfssc.id');

        $categories = [];

        foreach($shop_products as $sp)
        {
            $categories[$sp['category_id']][$sp['subcategory_id']][] = $sp;
        }
        
        foreach($categories as $categoryID => $subcategories)
        {
            foreach($subcategories as $subcategoryID => $shopProducts)
            {
                $products = $this->httpService->getProducts($categoryID, $subcategoryID);

                foreach($shopProducts as $shopProduct)
                {
                    $product = current(array_filter($products, fn($p) => $p['id'] == $shopProduct['id']));

                    $this->syncProduct($product, $shopProduct['id'], $shopProduct['id_product'], $shopProduct['ignore_deadline']);
                }
            }
        }
    }

    public function syncProduct($product, $blackfireProductID, $id_shop_product, $ignore_deadline)
    {
        $update_data = [];

        if ($product) {
            $release_date = DateTime::createFromFormat('d/m/Y', $product['release_date']);
            if ($release_date)
            {
                $release_date = $release_date->format('Y-m-d');
                $update_data['available_date'] = $release_date;
            }

            $order_deadline = DateTime::createFromFormat('d/m/Y', $product['preorder_deadline']);
        
            switch($product['status'])
            {
                case 'Closed':
                    $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_NOT_AVAILABLE;
                    break;

                case 'Close-out':
                case 'On Sale':
                    if ($product['stock'] == 'in stock')
                    {
                        $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_AVAILABLE;
                    }
                    else if ($product['stock'] == 'No stock')
                    {
                        $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_NOT_AVAILABLE;
                    }
                    else
                    {
                        throw "dupa";
                    }
                    break;

                case 'Preorder':
                    if ($ignore_deadline || !$order_deadline || $order_deadline > new DateTime("now"))
                        $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_AVAILABLE;
                    else
                        $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_NOT_AVAILABLE;
                    break;

                default:
                    $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_DEFAULT;
                    break;
            }
        } else {
            $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_DEFAULT;
        }

        $product_result = DB::getInstance()->update('product', $update_data, 'id_product = ' . $id_shop_product);
        $stock_result = DB::getInstance()->update('stock_available', ['out_of_stock' => $update_data['out_of_stock']], 'id_product = ' . $id_shop_product);

        if (array_key_exists('available_date', $update_data))
        {
            $product_details_result = [
                'product_shop' => DB::getInstance()->update('product_shop', ['available_date' => $update_data['available_date']], 'id_product = ' . $id_shop_product),
                'product_attribute' => DB::getInstance()->update('product_attribute', ['available_date' => $update_data['available_date']], 'id_product = ' . $id_shop_product),
                'product_attribute_shop' => DB::getInstance()->update('product_attribute_shop', ['available_date' => $update_data['available_date']], 'id_product = ' . $id_shop_product),
            ];
        }
        else
        {
            $product_details_result = null;
        }

        $log_array = [
            'id' => $blackfireProductID,
            'id_shop_product' => $id_shop_product,
            'out_of_stock' => $update_data['out_of_stock'],
            'release' => $product ? $product['release_date'] : 'n/a',
            'deadline' => $product ? $product['preorder_deadline'] : 'n/a',
            'stock_level' => $product ? $product['stock'] : 'n/a',
            'product_result' => $product_result,
            'product_details_result' => $product_details_result,
            'stock_result' => $stock_result,
        ];

        if ($product_result && $stock_result)
        {
            $this->logger->info('sync() ' . $blackfireProductID . ' ' . $id_shop_product, $log_array);
        }
        else
        {
            $this->logger->error('sync() ' . $blackfireProductID . ' ' . $id_shop_product, $log_array);
        }
    }

    public function syncCategoriesLocally()
    {
        $categories = $this->httpService->getCategories();

        foreach($categories as $category)
        {
            DB::getInstance()->insert('blackfiresync_categories', [
                [
                    'name' => $category['name'],
                    'link' => $category['link']
                ]
            ], false, true, DB::ON_DUPLICATE_KEY);   
        }

        $local_categories = DB::getInstance()->executeS('SELECT c.*
            FROM `'._DB_PREFIX_.'blackfiresync_categories` c');

        foreach ($local_categories as $key => $cat)
        {
            $local_categories[$cat['link']] = $cat;
        }

        foreach ($categories as $category)
        {
            $category_id = $local_categories[$category['link']]['id'];

            foreach ($category['subcategories'] as $subcategory)
            {
                $subcategory['category_id'] = $category_id;
                DB::getInstance()->insert('blackfiresync_subcategories', $subcategory, false, true, DB::ON_DUPLICATE_KEY);
            }

        }
    }

    private static $instances = [];

    protected function __construct() 
    { 
        $this->context = Context::getContext();
        $this->logger = new Logger('blackfire_sync');
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../../var/logs/blackfire_sync.log', Logger::INFO));

        $user = Configuration::get('BLACKFIRESYNC_ACCOUNT_EMAIL', null);
        $password = Configuration::get('BLACKFIRESYNC_ACCOUNT_PASSWORD', null);

        $this->httpService = new HttpService($user, $password);
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