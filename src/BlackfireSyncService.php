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
        return static::getInstance()->httpService->getCategories();
    }

    public static function getProducts($categoryID, $subcategoryID)
    {
        return static::getInstance()->getProductsFromSubcategory($categoryID, $subcategoryID);
    }

    public static function setShopProduct($id_product, $id_shop_product, $id_category)
    {
        return static::getInstance()->updateShopProduct($id_product, $id_shop_product, $id_category);
    }

    public static function cleanShopProduct($id_product)
    {
        return static::getInstance()->deleteShopProduct($id_product);
    }

    public static function createShopProduct($id_product, $id_category)
    {
        return static::getInstance()->newShopProduct($id_product, $id_category);
    }

    public static function syncProducts()
    {
        return static::getInstance()->sync();
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

    public function updateShopProduct($id_product, $id_shop_product, $id_category)
    {
        $result_delete = DB::getInstance()->delete('blackfiresync_products', 'id = ' . $id_product);
        $result_insert = DB::getInstance()->execute('INSERT INTO `'._DB_PREFIX_.'blackfiresync_products` 
            (`id`, `id_shop_product`, `id_category`) VALUES ('.$id_product.','.$id_shop_product.','.$id_category.')');

        if ($result_delete && $result_insert)
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::updateShopProduct | product: ' . $id_product . ' | category: ' . $id_category, 1, null, 'Product', $id_shop_product);
        }
        else
        {
            \PrestaShopLogger::addLog('BlackfireSyncService::updateShopProduct | product: ' . $id_product . ' | category: ' . $id_category, 3, null, 'Product', $id_shop_product);
            $this->logger->error('updateShopProduct() ' . $id_product . ' ' . $id_shop_product, [
                'result_delete' => $result_delete,
                'result_insert' => $result_insert,
                'id_product' => $id_product,
                'id_shop_product' => $id_shop_product,
                'id_category' => $id_category
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

    public function newShopProduct($id_product, $id_category)
    {
        $products = $this->httpService->getProducts($id_category);
        $product = current(array_filter($products, fn($p) => $p['ID'] == $id_product));
        $product_details = $this->httpService->getProduct($id_product);

        $price = floatval($product['Your Price']) * 5 * 1.5 * 1.23;
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

        $shop_product->reference = $product['Item-ID'];

        // VAT 23%
        $shop_product->price = $price;
        $shop_product->id_tax_rules_group = TaxRulesGroup::getIdByName('PL Standard Rate (23%)');

        $shop_product->wholesale_price = floatval($product['Your Price']) * 4.7;

        $shop_product->ean13 =  ltrim(preg_replace('/[^0-9]/s','',$product['EAN']), '0');
        $shop_product->active = false;
        $shop_product->weight = round(0.003 * $price, 3);

        $shop_product->id_manufacturer = Manufacturer::getIdByName($product['Producer']);
        $shop_product->manufacturer_name = $product['Producer'];

        if ($shop_product->add())
        {
            $this->updateShopProduct($id_product, $shop_product->id, $id_category);
            $this->syncProduct($product, $id_product, $shop_product->id, false);

            $image = new Image();
            $image->id_product = $shop_product->id;
            $image->cover = true;
            if ($image->add()) {
                if (!ImageHelpers::copyImg($shop_product->id, $image->id, $product['Image URL'], 'products', false)) {
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
        $shop_products = DB::getInstance()->executeS('SELECT bfsp.*, p.*
            FROM `'._DB_PREFIX_.'blackfiresync_products` bfsp
            JOIN `'._DB_PREFIX_.'product` p ON bfsp.`id_shop_product` = p.`id_product`');

        $categories = [];

        foreach($shop_products as $sp)
        {
            $categories[$sp['id_category']][] = $sp;
        }
        
        foreach($categories as $subcategoryID => $shop_products)
        {
            $products = $this->httpService->getProducts($subcategoryID);

            foreach($shop_products as $shop_product)
            {
                $product = current(array_filter($products, fn($p) => $p['ID'] == $shop_product['id']));

                $this->syncProduct($product, $shop_product['id'], $shop_product['id_product'], $shop_product['ignore_deadline']);
            }
        }
    }

    private function syncProduct($product, $id_product, $id_shop_product, $ignore_deadline)
    {
        $update_data = [];

        if ($product) {
            $release_date = DateTime::createFromFormat('d.m.Y', $product['Release Date']);
            if ($release_date)
            {
                $release_date = $release_date->format('Y-m-d');
                $update_data['available_date'] = $release_date;
            }

            $order_deadline = DateTime::createFromFormat('d.m.Y', $product['Order Deadline']);
            
            switch($product['Stock Level'])
            {
                case 'not on sale':
                    $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_NOT_AVAILABLE;
                    break;

                case 'in stock':
                    $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_AVAILABLE;
                    break;

                case 'low stock':
                    $update_data['out_of_stock'] = OutOfStockType::OUT_OF_STOCK_AVAILABLE;
                    break;

                case 'for preorder':
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
            'id' => $id_product,
            'id_shop_product' => $id_shop_product,
            'out_of_stock' => $update_data['out_of_stock'],
            'release' => $product ? $product['Release Date'] : 'n/a',
            'deadline' => $product ? $product['Order Deadline'] : 'n/a',
            'stock_level' => $product ? $product['Stock Level'] : 'n/a',
            'product_result' => $product_result,
            'product_details_result' => $product_details_result,
            'stock_result' => $stock_result,
        ];

        if ($product_result && $stock_result)
        {
            $this->logger->info('sync() ' . $id_product . ' ' . $id_shop_product, $log_array);
        }
        else
        {
            $this->logger->error('sync() ' . $id_product . ' ' . $id_shop_product, $log_array);
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