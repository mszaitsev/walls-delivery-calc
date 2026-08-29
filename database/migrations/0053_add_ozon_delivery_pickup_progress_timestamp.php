<?php
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
return static function (): void { global $wpdb; $table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_generations'; $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '` LIKE %s', 'progress_updated_at' ) ); if ( 'progress_updated_at' !== $exists ) { $result = $wpdb->query( 'ALTER TABLE `' . str_replace( '`', '``', $table ) . '` ADD COLUMN progress_updated_at datetime NULL AFTER completed_at' ); if ( false === $result ) { throw new RuntimeException( 'Ozon pickup progress timestamp migration failed.' ); } } };
