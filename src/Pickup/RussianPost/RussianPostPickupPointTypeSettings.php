<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupPointTypeSettings {
	public const TYPES = array( 'OPS', 'PVZ', 'APS' );

	public function __construct(
		private ?SettingsRepository $settings = null,
		private ?DeliveryServiceRepository $services = null,
		private ?DeliveryServiceSettingsRepository $service_settings = null
	) {
	}

	/**
	 * @return array<string,array{enabled:bool,label:string}>
	 */
	public static function defaults(): array {
		return array(
			'OPS' => array( 'enabled' => true, 'label' => 'Отделение Почты России' ),
			'PVZ' => array( 'enabled' => true, 'label' => 'Пункт выдачи' ),
			'APS' => array( 'enabled' => true, 'label' => 'Почтомат' ),
		);
	}

	/**
	 * @return array<string,array{enabled:bool,label:string}>
	 */
	public function all(): array {
		$settings = $this->stored_settings();
		$result = self::defaults();
		foreach ( self::TYPES as $type ) {
			$key = strtolower( $type );
			$result[ $type ]['enabled'] = array_key_exists( "russian_post_domestic_point_type_{$key}_enabled", $settings )
				? ! empty( $settings[ "russian_post_domestic_point_type_{$key}_enabled" ] )
				: $result[ $type ]['enabled'];
			$result[ $type ]['label'] = $this->label_or_default(
				$settings[ "russian_post_domestic_point_type_{$key}_label" ] ?? '',
				$result[ $type ]['label']
			);
		}
		if ( array() === $this->enabled_types_from_config( $result ) ) {
			$result['OPS']['enabled'] = true;
		}

		return $result;
	}

	/**
	 * @return array<int,string>
	 */
	public function enabled_types(): array {
		return $this->enabled_types_from_config( $this->all() );
	}

	/**
	 * @param array<int,string> $requested
	 * @return array<int,string>
	 */
	public function allowed_types( array $requested = array() ): array {
		$enabled = $this->enabled_types();
		if ( array() === $requested ) {
			return $enabled;
		}
		$requested = array_values( array_filter( array_map( static fn( mixed $type ): string => strtoupper( trim( (string) $type ) ), $requested ), static fn( string $type ): bool => in_array( $type, self::TYPES, true ) ) );

		return array_values( array_intersect( $requested, $enabled ) );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,array{value:mixed,format:string}>
	 */
	public function sanitize_admin_values( array $data ): array {
		$result = array();
		$any_enabled = false;
		$defaults = self::defaults();
		foreach ( self::TYPES as $type ) {
			$key = strtolower( $type );
			$enabled_key = "russian_post_domestic_point_type_{$key}_enabled";
			$label_key = "russian_post_domestic_point_type_{$key}_label";
			$enabled = ! empty( $data[ $enabled_key ] );
			$any_enabled = $any_enabled || $enabled;
			$result[ $enabled_key ] = array( 'value' => $enabled, 'format' => 'bool' );
			$result[ $label_key ] = array( 'value' => $this->label_or_default( $data[ $label_key ] ?? '', $defaults[ $type ]['label'] ), 'format' => 'string' );
		}
		if ( ! $any_enabled ) {
			$result['russian_post_domestic_point_type_ops_enabled']['value'] = true;
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function stored_settings(): array {
		$service = $this->service();
		if ( $service instanceof DeliveryService && null !== $service->id && $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			return $this->service_settings->all_settings( (int) $service->id );
		}

		return $this->settings instanceof SettingsRepository ? $this->settings->all() : array();
	}

	private function service(): ?DeliveryService {
		return $this->services instanceof DeliveryServiceRepository
			? $this->services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY )
			: null;
	}

	private function label_or_default( mixed $value, string $default ): string {
		$text = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( (string) $value ) : strip_tags( (string) $value ) );

		return '' !== $text ? $text : $default;
	}

	/**
	 * @param array<string,array{enabled:bool,label:string}> $config
	 * @return array<int,string>
	 */
	private function enabled_types_from_config( array $config ): array {
		return array_values( array_filter( self::TYPES, static fn( string $type ): bool => ! empty( $config[ $type ]['enabled'] ) ) );
	}
}
