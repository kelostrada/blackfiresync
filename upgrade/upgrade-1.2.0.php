<?php
/**
* 2007-2021 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2021 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
if (!defined('_PS_VERSION_')) {
    exit;
}

use Blackfire\BlackfireSyncService;
use Blackfire\Utils;

/**
 * This function updates your module from previous versions to the version 1.1,
 * usefull when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_1_2_0($module)
{
    /*
     * Do everything you want right there,
     * You could add a column in one of your module's tables
     */

    $result = true;
    $result &= Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'blackfiresync` MODIFY `cookie` TEXT');
    // $result &= Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'blackfiresync_products` MODIFY `id_category` TEXT');

    $result &= Db::getInstance()->execute('
    CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blackfiresync_categories` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` TEXT,
        `link` VARCHAR(1000) NOT NULL UNIQUE,
        PRIMARY KEY  (`id`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

    $result &= Db::getInstance()->execute('
    CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'blackfiresync_subcategories` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` TEXT,
        `link` VARCHAR(1000) NOT NULL UNIQUE,
        `category_id` int(11) UNSIGNED NOT NULL,
        PRIMARY KEY  (`id`),
        FOREIGN KEY (`category_id`) REFERENCES `' . _DB_PREFIX_ . 'blackfiresync_categories`(`id`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');

    $result &= Db::getInstance()->execute('
    ALTER TABLE `' . _DB_PREFIX_ . 'blackfiresync_products` 
    ADD `subcategory_id` int(11) UNSIGNED,
    ADD FOREIGN KEY (subcategory_id) REFERENCES `' . _DB_PREFIX_ . 'blackfiresync_subcategories`(id);');

    $category_links = [
        12 => '/en-gb/card-game-supplies/product%20category=card%20-1=%20game%20supplies-4=bushiroad%20standard%20sleeves/',
        17 => '/en-gb/card-game-supplies/product%20category=card%20-1=%20game%20supplies-4=bushiroad%20small%20sleeves/',
        32 => '/en-gb/card-game-supplies/product%20category=card%20-1=%20game%20supplies-4=bushiroad%20supplies/',
        38 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=wei%C3%9F%20-3=%20schwarz/',
        39 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=cardfight!!%20vanguard/',
        70 => '/en-gb/card-game-supplies/product%20category=card%20-1=%20game%20supplies-4=dragon%20shield%20standard%20sleeves/',
        293 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=final%20fantasy%20tcg/',
        863 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=digimon%20tcg/',
        886 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=flesh%20-1=%20blood%20tcg/',
        914 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=cardfight!!%20vanguard/', // Close out - but i got only CFV
        1115 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=one%20piece%20tcg/',
        1137 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=grand%20archive%20tcg/',
        1158 => '/en-gb/trading-card-games/product%20category=trading%20card%20games-4=battle%20spirits%20saga/' 
    ];

    BlackfireSyncService::syncCategories();
    $all_categories = BlackfireSyncService::getCategories();

    foreach ($category_links as $old_category_id => $category_link)
    {
        $found = false;

        foreach($all_categories as $c)
        {
            foreach($c['subcategories'] as $s)
            {
                if ($s['link'] == $category_link) {
                    $result &= Db::getInstance()->update('blackfiresync_products', [
                        'subcategory_id' => $s['id'],
                    ], '`id_category` = \'' . pSQL($old_category_id) . '\'', false, true);

                    $found = true;
                    break;
                }
            }

            if ($found) break;
        }
    }

    return $result;
}
