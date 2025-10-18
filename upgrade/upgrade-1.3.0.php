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

/**
 * This function updates your module from previous versions to the version 1.3,
 * useful when you modify your database, or register a new hook ...
 * Don't forget to create one file per version.
 */
function upgrade_module_1_3_0($module)
{
    /*
     * Do everything you want right there,
     * You could add a column in one of your module's tables
     */

    $result = true;
    
    // Version 1.3.0 improvements:
    // - Enhanced UI styling for better user experience
    // - Improved login process with RequestVerificationToken support
    // - Added Chrome-like headers for better compatibility
    // - Enhanced JavaScript interactions
    // - Better responsive design
    // - Improved form controls and select pickers
    
    // No database changes needed for this version
    // All improvements are in the UI/UX layer
    
    return $result;
}
