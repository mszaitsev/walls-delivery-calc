<?php
/**
 * Plugin Name: РљР°Р»СЊРєСѓР»СЏС‚РѕСЂ РґРѕСЃС‚Р°РІРѕРє walls-shop.ru
 * Author: РњРёС…Р°РёР» Р—Р°Р№С†РµРІ
 * Description: Р Р°СЃС‡РµС‚ СЃС‚РѕРёРјРѕСЃС‚Рё РґРѕСЃС‚Р°РІРєРё РґР»СЏ WooCommerce.
 * Text Domain: walls-delivery-calc
 * Version: 0.128.2
 * Requires at least: 6.8
 * Requires PHP: 8.4
 * WC requires at least: 9.0
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

define( 'WDC_PLUGIN_FILE', __FILE__ );
define( 'WDC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WDC_VERSION', '0.128.2' );

require_once WDC_PLUGIN_DIR . 'src/Core/bootstrap.php';

wdc_bootstrap_core_platform();
