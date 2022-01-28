<?php

namespace Blackfire;

use \Configuration;
use \Context;
use \DateTime;
use \Db;
use \Shop;

use Blackfire\HttpService;
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

    public static function getProducts($subcategoryID)
    {
        return static::getInstance()->getProductsFromSubcategory($subcategoryID);
    }

    public static function setShopProduct($id_product, $id_shop_product, $id_category)
    {
        return static::getInstance()->updateShopProduct($id_product, $id_shop_product, $id_category);
    }

    public static function syncProducts()
    {
        return static::getInstance()->sync();
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

        foreach($bfproducts as &$bfp)
        {
            $shop_product = current(array_filter($shop_products, fn($p) => $p['blackfire_id'] == $bfp['ID']));
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

            foreach($shop_products as $sp)
            {
                $update_data = [];

                $product = current(array_filter($products, fn($p) => $p['ID'] == $sp['id']));

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
                            if (!$order_deadline || $order_deadline > new DateTime("now"))
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

                $product_result = DB::getInstance()->update('product', $update_data, 'id_product = ' . $sp['id_shop_product']);
                $stock_result = DB::getInstance()->update('stock_available', ['out_of_stock' => $update_data['out_of_stock']], 'id_product = ' . $sp['id_shop_product']);

                if (array_key_exists('available_date', $update_data))
                {
                    $product_details_result = [
                        'product_shop' => DB::getInstance()->update('product_shop', ['available_date' => $update_data['available_date']], 'id_product = ' . $sp['id_shop_product']),
                        'product_attribute' => DB::getInstance()->update('product_attribute', ['available_date' => $update_data['available_date']], 'id_product = ' . $sp['id_shop_product']),
                        'product_attribute_shop' => DB::getInstance()->update('product_attribute_shop', ['available_date' => $update_data['available_date']], 'id_product = ' . $sp['id_shop_product']),
                    ];
                }
                else
                {
                    $product_details_result = null;
                }

                $log_array = [
                    'id' => $sp['id'],
                    'id_shop_product' => $sp['id_shop_product'],
                    'out_of_stock' => $update_data['out_of_stock'],
                    'release' => $product['Release Date'],
                    'deadline' => $product['Order Deadline'],
                    'stock_level' => $product['Stock Level'],
                    'product_result' => $product_result,
                    'product_details_result' => $product_details_result,
                    'stock_result' => $stock_result,
                ];

                if ($product_result && $stock_result)
                {
                    $this->logger->info('sync() ' . $sp['id'] . ' ' . $sp['id_shop_product'], $log_array);
                }
                else
                {
                    $this->logger->error('sync() ' . $sp['id'] . ' ' . $sp['id_shop_product'], $log_array);
                }
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