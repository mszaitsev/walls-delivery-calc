<?php
/**
 * Plugin Name: Калькулятор доставок walls-shop.ru
 * Author: Михаил Зайцев
 * Description: Расчет стоимости доставки для WooCommerce.
 * Text Domain: walls-delivery-calc
 * Version: 0.91.0
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
define( 'WDC_VERSION', '0.91.0' );

require_once WDC_PLUGIN_DIR . 'src/Core/bootstrap.php';

wdc_bootstrap_core_platform();
