<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentServiceSettings {
	public const SHELF_LIFE_DAYS_DEFAULT = 'shelf_life_days_default';
	public const SEND_GOODS_ITEMS = 'send_goods_items';
	public const COMBINE_GOODS_ITEMS_DEFAULT = 'combine_goods_items_default';
	public const COMBINED_GOODS_NAME_TEMPLATE = 'combined_goods_name_template';

	public function __construct( private ?DeliveryServiceSettingsRepository $settings = null ) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults( string $service_key = '' ): array {
		$defaults = array(
			self::SEND_GOODS_ITEMS => false,
			self::COMBINE_GOODS_ITEMS_DEFAULT => true,
			self::COMBINED_GOODS_NAME_TEMPLATE => 'Товары по заказу {order_number}',
		);
		if ( RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $service_key ) {
			$defaults[ self::SHELF_LIFE_DAYS_DEFAULT ] = 30;
		}

		return $defaults;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function for_service( ?DeliveryService $service ): array {
		if ( ! $service instanceof DeliveryService || null === $service->id ) {
			return self::defaults();
		}
		$stored = $this->settings instanceof DeliveryServiceSettingsRepository ? $this->settings->all_settings( (int) $service->id ) : array();
		$result = array_merge( self::defaults( $service->service_key ), $stored );
		$result[ self::SHELF_LIFE_DAYS_DEFAULT ] = max( 15, min( 60, (int) ( $result[ self::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ) );
		$result[ self::SEND_GOODS_ITEMS ] = ! empty( $result[ self::SEND_GOODS_ITEMS ] );
		$result[ self::COMBINE_GOODS_ITEMS_DEFAULT ] = ! empty( $result[ self::COMBINE_GOODS_ITEMS_DEFAULT ] );
		$result[ self::COMBINED_GOODS_NAME_TEMPLATE ] = trim( (string) ( $result[ self::COMBINED_GOODS_NAME_TEMPLATE ] ?? '' ) ) ?: self::defaults()[ self::COMBINED_GOODS_NAME_TEMPLATE ];

		return $result;
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	public static function sanitize_from_post( array $post, string $service_key ): array {
		$result = array(
			self::SEND_GOODS_ITEMS => array( 'value' => ! empty( $post[ self::SEND_GOODS_ITEMS ] ), 'format' => 'bool' ),
			self::COMBINE_GOODS_ITEMS_DEFAULT => array( 'value' => ! empty( $post[ self::COMBINE_GOODS_ITEMS_DEFAULT ] ), 'format' => 'bool' ),
			self::COMBINED_GOODS_NAME_TEMPLATE => array( 'value' => sanitize_text_field( wp_unslash( $post[ self::COMBINED_GOODS_NAME_TEMPLATE ] ?? self::defaults()[ self::COMBINED_GOODS_NAME_TEMPLATE ] ) ), 'format' => 'string' ),
		);
		if ( RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $service_key ) {
			$result[ self::SHELF_LIFE_DAYS_DEFAULT ] = array( 'value' => max( 15, min( 60, (int) ( $post[ self::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ) ), 'format' => 'number' );
		}

		return $result;
	}
}
