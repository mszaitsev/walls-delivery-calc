<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationBuilder;

defined( 'ABSPATH' ) || exit;

final class CdekCreateRequestBuilder {
	public const PRINT_TYPE = 'BARCODE';

	public function __construct(
		private CdekSettings $settings
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build( ShipmentCreateRequest $request ): array {
		$errors = $this->validate( $request );
		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( "\n", $errors ) );
		}

		$mode = $this->delivery_mode( $request );
		$payload = array(
			'type' => 1,
			'number' => $this->order_number( $request ),
			'tariff_code' => (int) $this->tariff_code( $request ),
			'recipient' => $this->recipient( $request ),
			'packages' => $this->packages( $request ),
			'print' => self::PRINT_TYPE,
		);
		$comment = $this->comment( $request );
		if ( '' !== $comment ) {
			$payload['comment'] = $comment;
		}

		if ( in_array( $mode, array( 1, 2 ), true ) ) {
			$payload['from_location'] = $this->from_location();
		} else {
			$payload['shipment_point'] = $this->shipment_point( $request );
		}

		if ( in_array( $mode, array( 2, 4 ), true ) ) {
			$payload['delivery_point'] = $this->delivery_point( $request );
		} else {
			$payload['to_location'] = $this->to_location( $request );
		}

		return $payload;
	}

	/**
	 * @return array<int,string>
	 */
	public function validate( ShipmentCreateRequest $request ): array {
		$errors = array();
		if ( CdekSettings::CARRIER_KEY !== $request->carrier_key ) {
			$errors[] = 'Заказ не относится к CDEK.';
		}
		$recipient_country = $this->recipient_country_code( $request );
		if ( '' !== $recipient_country && ! in_array( $recipient_country, CdekSettings::SUPPORTED_COUNTRIES, true ) ) {
			$errors[] = sprintf( 'CDEK does not support recipient country %s.', $recipient_country );
		}
		if ( '' === trim( (string) ( $request->recipient['name'] ?? '' ) ) ) {
			$errors[] = 'Заполните получателя СДЭК.';
		}
		if ( '' === $this->phone( (string) ( $request->recipient['phone'] ?? '' ) ) ) {
			$errors[] = 'Заполните корректный телефон получателя СДЭК.';
		}
		if ( '' === $this->tariff_code( $request ) ) {
			$errors[] = 'Не выбран tariff_code СДЭК.';
		}
		$mode = $this->delivery_mode( $request );
		if ( 0 === $mode ) {
			$errors[] = 'Не удалось определить режим тарифа СДЭК. Проверьте тариф и повторите создание отправления.';
		}
		if ( in_array( $mode, array( 3, 4 ), true ) && '' === $this->shipment_point( $request ) ) {
			$errors[] = 'Заполните код ПВЗ отправления СДЭК.';
		}
		if ( in_array( $mode, array( 1, 2 ), true ) && ( $this->settings->sender_city_code() <= 0 || '' === $this->settings->sender_address() ) ) {
			$errors[] = 'Заполните код города и адрес отправителя СДЭК для тарифа от двери.';
		}
		if ( in_array( $mode, array( 2, 4 ), true ) && '' === $this->delivery_point( $request ) ) {
			$errors[] = 'Для CDEK pickup нужен код ПВЗ delivery_point.';
		}
		if ( in_array( $mode, array( 1, 3 ), true ) && '' === trim( $request->recipient_address->raw_address ) ) {
			$errors[] = 'Для CDEK courier нужен адрес доставки to_location.';
		}
		if ( in_array( $mode, array( 1, 3 ), true ) && $this->to_location_city_code( $request ) <= 0 ) {
			$errors[] = "Не удалось определить код города СДЭК для адреса получателя.\nПроверьте адрес и повторите обработку.";
		}
		if ( array() === $request->places ) {
			$errors[] = 'Добавьте хотя бы одно грузоместо.';
		}

		$item_rows_count = 0;
		$known_places = array();
		$place_weights = array();
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$known_places[ $place->place_number ] = true;
			$place_weights[ $place->place_number ] = 0;
			foreach ( array( 'weight_g' => $place->weight_g, 'length_cm' => $place->length_cm, 'width_cm' => $place->width_cm, 'height_cm' => $place->height_cm ) as $field => $value ) {
				if ( $value <= 0 ) {
					$errors[] = sprintf( 'Грузоместо %d: %s должен быть больше 0.', $place->place_number, $field );
				}
			}
		}

		foreach ( $this->item_rows( $request ) as $row ) {
			++$item_rows_count;
			$place_number = (int) ( $row['place_number'] ?? 0 );
			if ( ! isset( $known_places[ $place_number ] ) ) {
				$errors[] = 'Каждый товар должен быть назначен в существующее грузоместо.';
			}
			$amount = (int) ( $row['amount'] ?? 0 );
			$weight = (int) ( $row['weight'] ?? 0 );
			$cost = is_numeric( $row['assessed_unit_price_kopecks'] ?? null ) ? (int) $row['assessed_unit_price_kopecks'] : -1;
			if ( $amount <= 0 ) {
				$errors[] = 'Количество товара СДЭК должно быть больше 0.';
			}
			if ( $weight <= 0 ) {
				$errors[] = 'Вес товара СДЭК должен быть больше 0.';
			}
			if ( $cost < 0 ) {
				$errors[] = 'Объявленная стоимость товара СДЭК должна быть не меньше 0.';
			}
			if ( isset( $place_weights[ $place_number ] ) ) {
				$place_weights[ $place_number ] += $weight * $amount;
			}
		}
		if ( $item_rows_count > 126 ) {
			$errors[] = 'В заказе больше 126 товарных строк СДЭК.';
		}
		try {
			( new ShipmentAllocationBuilder() )->build( $this->item_rows( $request ), $request->places );
		} catch ( \InvalidArgumentException $exception ) {
			$errors[] = $exception->getMessage();
		}
		foreach ( $request->places as $place ) {
			if ( $place instanceof ShipmentPlace && ( $place_weights[ $place->place_number ] ?? 0 ) > $place->weight_g ) {
				$errors[] = sprintf( 'Вес грузоместа %d меньше суммы весов товаров.', $place->place_number );
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function split_item_rows( array $rows, string $item_key, int $duplicate_quantity ): array {
		foreach ( $rows as $index => $row ) {
			if ( (string) ( $row['item_key'] ?? '' ) !== $item_key || ! empty( $row['split_parent'] ) ) {
				continue;
			}
			$total = max( 1, (int) ( $row['ordered_quantity'] ?? $row['amount'] ?? 1 ) );
			$duplicate_quantity = max( 1, min( $total - 1, $duplicate_quantity ) );
			$rows[ $index ]['amount'] = $total - $duplicate_quantity;
			$duplicate = $row;
			$duplicate['amount'] = $duplicate_quantity;
			$duplicate['split_parent'] = $item_key;
			$duplicate['row_key'] = $item_key . ':split:' . ( count( $rows ) + 1 );
			array_splice( $rows, $index + 1, 0, array( $duplicate ) );
			break;
		}

		return self::rebalance_split_rows( $rows );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function rebalance_split_rows( array $rows ): array {
		$children = array();
		foreach ( $rows as $row ) {
			$parent = (string) ( $row['split_parent'] ?? '' );
			if ( '' !== $parent ) {
				$children[ $parent ] = ( $children[ $parent ] ?? 0 ) + max( 0, (int) ( $row['amount'] ?? 0 ) );
			}
		}
		foreach ( $rows as $index => $row ) {
			$item_key = (string) ( $row['item_key'] ?? '' );
			if ( '' === $item_key || ! empty( $row['split_parent'] ) || ! isset( $children[ $item_key ] ) ) {
				continue;
			}
			$total = max( 1, (int) ( $row['ordered_quantity'] ?? $row['amount'] ?? 1 ) );
			$rows[ $index ]['amount'] = max( 1, $total - $children[ $item_key ] );
		}

		return $rows;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function recipient( ShipmentCreateRequest $request ): array {
		$recipient = array(
			'name' => trim( (string) ( $request->recipient['name'] ?? '' ) ),
			'phones' => array(
				array( 'number' => $this->phone( (string) ( $request->recipient['phone'] ?? '' ) ) ),
			),
		);
		$email = trim( (string) ( $request->recipient['email'] ?? '' ) );
		if ( '' !== $email && filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$recipient['email'] = $email;
		}
		$country = $this->recipient_country_code( $request );
		$document = $this->recipient_document( $request );
		if ( '' !== $document && in_array( $country, array( 'KZ', 'KG' ), true ) ) {
			$recipient['tin'] = $document;
		}
		if ( '' !== $document && in_array( $country, array( 'AM', 'BY' ), true ) ) {
			$recipient['passport_number'] = $document;
		}

		return $recipient;
	}

	private function recipient_country_code( ShipmentCreateRequest $request ): string {
		$country_code = strtoupper( trim( (string) $request->recipient_address->country_code ) );
		return '' === $country_code ? 'RU' : $country_code;
	}

	private function recipient_document( ShipmentCreateRequest $request ): string {
		$value = sanitize_text_field( wp_unslash( $request->recipient['tin'] ?? $request->recipient['passport_number'] ?? '' ) );
		$value = trim( preg_replace( '/[\x00-\x1F\x7F]+/u', '', $value ) ?? $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 30 ) : substr( $value, 0, 30 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function from_location(): array {
		$location = array(
			'code' => $this->settings->sender_city_code(),
			'address' => $this->settings->sender_address(),
		);
		if ( '' !== $this->settings->sender_city_name() ) {
			$location['city'] = $this->settings->sender_city_name();
		}
		if ( '' !== $this->settings->sender_postal_code() ) {
			$location['postal_code'] = $this->settings->sender_postal_code();
		}

		return $location;
	}

	private function shipment_point( ShipmentCreateRequest $request ): string {
		$point = preg_replace( '/[^A-Z0-9_\-]/', '', strtoupper( (string) ( $request->meta['shipment_point'] ?? '' ) ) ) ?? '';

		return '' !== $point ? $point : $this->settings->shipment_point();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function to_location( ShipmentCreateRequest $request ): array {
		$location = array_filter(
			array(
				'code' => $this->to_location_city_code( $request ),
				'city' => (string) ( $request->meta['cdek_city_name'] ?? $request->recipient_address->city ),
				'postal_code' => preg_replace( '/\D+/', '', (string) ( $request->meta['cdek_postal_code'] ?? $request->recipient_address->postcode ) ) ?: '',
				'address' => (string) ( $request->meta['cdek_delivery_address'] ?? $request->recipient_address->raw_address ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);

		return $location;
	}

	private function to_location_city_code( ShipmentCreateRequest $request ): int {
		$code = is_numeric( $request->meta['cdek_city_code'] ?? null )
			? (int) $request->meta['cdek_city_code']
			: ( is_numeric( $request->meta['cdek_to_city_code'] ?? null ) ? (int) $request->meta['cdek_to_city_code'] : 0 );

		return $code > 0 ? $code : 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function packages( ShipmentCreateRequest $request ): array {
		$rows_by_place = array();
		foreach ( $this->item_rows( $request ) as $row ) {
			$rows_by_place[ (int) $row['place_number'] ][] = array(
				'name' => (string) $row['name'],
				'ware_key' => substr( preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $row['sku'] ) ?: 'item' . (string) ( $row['item_id'] ?? '' ), 0, 20 ),
				'payment' => array( 'value' => 0 ),
				'cost' => $this->rubles_from_kopecks( (int) $row['assessed_unit_price_kopecks'] ),
				'weight' => (int) $row['weight'],
				'amount' => (int) $row['amount'],
			);
		}
		$packages = array();
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$packages[] = array(
				'number' => (string) $place->place_number,
				'weight' => $place->weight_g,
				'length' => $place->length_cm,
				'width' => $place->width_cm,
				'height' => $place->height_cm,
				'items' => array_values( $rows_by_place[ $place->place_number ] ?? array() ),
			);
		}

		return $packages;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function item_rows( ShipmentCreateRequest $request ): array {
		$rows = is_array( $request->meta['shipment_item_rows'] ?? null ) ? $request->meta['shipment_item_rows'] : array();

		return array_values( array_filter( $rows, 'is_array' ) );
	}

	private function rubles_from_kopecks( int $kopecks ): float {
		return round( $kopecks / 100, 2 );
	}

	private function delivery_mode( ShipmentCreateRequest $request ): int {
		$mode = (int) ( $request->meta['delivery_mode'] ?? $request->meta['cdek_delivery_mode'] ?? 0 );
		if ( in_array( $mode, array( 1, 2, 3, 4 ), true ) ) {
			return $mode;
		}
		$title = strtolower( str_replace( '_', '-', (string) ( $request->meta['tariff_title'] ?? '' ) ) );
		if ( str_starts_with( $title, 'дверь-дверь' ) || str_starts_with( $title, 'door-door' ) ) {
			return 1;
		}
		if ( str_starts_with( $title, 'дверь-склад' ) || str_starts_with( $title, 'door-warehouse' ) ) {
			return 2;
		}
		if ( str_starts_with( $title, 'склад-дверь' ) || str_starts_with( $title, 'warehouse-door' ) ) {
			return 3;
		}
		if ( str_starts_with( $title, 'склад-склад' ) || str_starts_with( $title, 'warehouse-warehouse' ) ) {
			return 4;
		}
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			return 4;
		}
		if ( DeliveryType::COURIER === $request->delivery_type ) {
			return '' !== $this->shipment_point( $request ) ? 3 : 1;
		}

		return 0;
	}

	private function tariff_code( ShipmentCreateRequest $request ): string {
		return preg_replace( '/\D+/', '', (string) ( $request->meta['tariff_code'] ?? $request->meta['tariff_object'] ?? $request->rate_id ) ) ?: '';
	}

	private function delivery_point( ShipmentCreateRequest $request ): string {
		$code = trim( (string) ( $request->meta['delivery_point'] ?? $request->meta['pickup_point_code'] ?? $request->meta['point_code'] ?? $request->meta['cdek_code'] ?? $request->pickup_point?->point_code ?? '' ) );
		if ( preg_match( '/^\d{6}$/', $code ) ) {
			return '';
		}

		return strtoupper( preg_replace( '/[^A-Z0-9_\-]/', '', strtoupper( $code ) ) ?? '' );
	}

	private function comment( ShipmentCreateRequest $request ): string {
		$comment = trim( (string) ( $request->meta['cdek_courier_comment'] ?? $request->meta['comment'] ?? '' ) );
		$comment = preg_replace( '/\s+/', ' ', $comment ) ?? $comment;

		return function_exists( 'mb_substr' ) ? mb_substr( $comment, 0, 255 ) : substr( $comment, 0, 255 );
	}

	private function order_number( ShipmentCreateRequest $request ): string {
		return substr( preg_replace( '/[^\x20-\x7E]/', '', (string) ( $request->meta['order_num'] ?? $request->order_id ) ) ?: (string) $request->order_id, 0, 40 );
	}

	private function phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( 11 === strlen( $digits ) && ( str_starts_with( $digits, '8' ) || str_starts_with( $digits, '7' ) ) ) {
			return '+7' . substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) ) {
			return '+7' . $digits;
		}
		if ( str_starts_with( trim( $phone ), '+' ) && strlen( $digits ) >= 10 ) {
			return '+' . $digits;
		}

		return '';
	}
}
