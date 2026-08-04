<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;

defined( 'ABSPATH' ) || exit;

final class PekQuoteOptions {
	public const MODE_PICKUP = 'pickup';
	public const MODE_COURIER = 'courier';

	public readonly string $mode;
	public readonly string $planned_date_time;
	public readonly string $receiver_warehouse_id;
	public readonly string $delivery_address;
	public readonly ?float $delivery_latitude;
	public readonly ?float $delivery_longitude;

	public function __construct(
		string $mode,
		string $planned_date_time,
		string $receiver_warehouse_id = '',
		string $delivery_address = '',
		?float $delivery_latitude = null,
		?float $delivery_longitude = null
	) {
		$mode = strtolower( trim( $mode ) );
		if ( ! in_array( $mode, array( self::MODE_PICKUP, self::MODE_COURIER ), true ) ) {
			throw new PekApiException( 'Неподдерживаемый режим расчёта ПЭК.', array( 'error_code' => 'pek_quote_mode_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$planned_date_time = $this->normalize_text( $planned_date_time, 64 );
		if ( '' === $planned_date_time ) {
			throw new PekApiException( 'Не указана плановая дата расчёта ПЭК.', array( 'error_code' => 'pek_quote_planned_datetime_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$receiver_warehouse_id = $this->normalize_text( $receiver_warehouse_id, 128 );
		$delivery_address = $this->normalize_text( $delivery_address, 1000 );
		if ( self::MODE_PICKUP === $mode && '' === $receiver_warehouse_id ) {
			throw new PekApiException( 'Не указан склад выдачи ПЭК.', array( 'error_code' => 'pek_quote_receiver_warehouse_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		if ( self::MODE_COURIER === $mode && '' === $delivery_address ) {
			throw new PekApiException( 'Не указан адрес курьерской доставки ПЭК.', array( 'error_code' => 'pek_quote_delivery_address_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		if ( ( null === $delivery_latitude ) !== ( null === $delivery_longitude ) ) {
			throw new PekApiException( 'Координаты доставки ПЭК должны передаваться полной парой.', array( 'error_code' => 'pek_quote_delivery_coordinates_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		if ( null !== $delivery_latitude && ( ! is_finite( $delivery_latitude ) || $delivery_latitude < -90 || $delivery_latitude > 90 || ! is_finite( (float) $delivery_longitude ) || (float) $delivery_longitude < -180 || (float) $delivery_longitude > 180 ) ) {
			throw new PekApiException( 'Координаты доставки ПЭК вне допустимого диапазона.', array( 'error_code' => 'pek_quote_delivery_coordinates_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}

		$this->mode = $mode;
		$this->planned_date_time = $planned_date_time;
		$this->receiver_warehouse_id = self::MODE_PICKUP === $mode ? $receiver_warehouse_id : '';
		$this->delivery_address = self::MODE_COURIER === $mode ? $delivery_address : '';
		$this->delivery_latitude = self::MODE_COURIER === $mode ? $delivery_latitude : null;
		$this->delivery_longitude = self::MODE_COURIER === $mode ? $delivery_longitude : null;
	}

	public function is_pickup(): bool {
		return self::MODE_PICKUP === $this->mode;
	}

	public function is_courier(): bool {
		return self::MODE_COURIER === $this->mode;
	}

	private function normalize_text( string $value, int $max_length ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length );
		}

		return substr( $value, 0, $max_length );
	}
}
