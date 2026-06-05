<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class RussianPostCreateRequestBuilder {
	private const MAX_PLACE_WEIGHT_G = 31500;
	private const MAX_MMO_WEIGHT_G = 300000;

	public function __construct( private ?RussianPostShipmentProductMapper $products = null ) {
		$this->products ??= new RussianPostShipmentProductMapper();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function build( ShipmentCreateRequest $request ): array {
		$errors = $this->validate( $request );
		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( '; ', $errors ) );
		}

		$object_code = (string) ( $request->meta['tariff_object'] ?? $request->meta['selected_tariff_object'] ?? '' );
		$product = $this->products->by_object_code( $object_code, $request->delivery_type );
		$is_ecom = DeliveryType::PICKUP === $request->delivery_type && ! empty( $request->meta['tariff_is_ecom'] );
		$is_mmo = count( $request->places ) > 1;
		$order_num = $this->order_num( $request );
		$send_goods = ! empty( $request->services['send_goods_items'] );
		$combine_goods = ! empty( $request->services['combine_goods_items'] );
		$combined_name = trim( (string) ( $request->services['combined_goods_name'] ?? '' ) ) ?: str_replace( '{order_number}', $order_num, (string) ( $request->services['combined_goods_name_template'] ?? 'Товары по заказу {order_number}' ) );
		$phone = $this->normalize_phone( (string) ( $request->recipient['phone'] ?? '' ) );
		$result = array();

		foreach ( $request->places as $index => $place ) {
			$payload = array(
				'order-num' => $is_mmo ? $order_num . '-' . ( $index + 1 ) : $order_num,
				'mail-type' => $product['mail_type'],
				'mail-category' => $product['mail_category'],
				'mail-direct' => 643,
				'mass' => $place->weight_g,
				'dimension' => array(
					'length' => $place->length_cm,
					'width' => $place->width_cm,
					'height' => $place->height_cm,
				),
				'postoffice-code' => (string) ( $request->meta['postoffice_code'] ?? $request->meta['from_postcode'] ?? '' ),
				'recipient-name' => (string) ( $request->recipient['name'] ?? '' ),
				'tel-address' => $phone,
				'payment' => 0,
				'compulsory-payment' => 0,
				'delivery-with-cod' => false,
				'payment-method' => 'CASHLESS',
				'notice-payment-method' => 'CASHLESS',
			);

			if ( '' !== (string) ( $request->recipient['email'] ?? '' ) ) {
				$payload['mail-address'] = (string) $request->recipient['email'];
			}

			$payload = array_merge( $payload, $this->split_name( (string) ( $request->recipient['name'] ?? '' ) ) );

			if ( ! empty( $product['has_declared_value'] ) && $place->declared_value->get_kopecks() > 0 ) {
				$payload['insr-value'] = $place->declared_value->get_kopecks();
			}

			if ( DeliveryType::COURIER === $request->delivery_type ) {
				$payload = array_merge( $payload, $this->courier_address_fields( $request ) );
			} else {
				$pickup_code = $request->pickup_point?->point_code ?? (string) ( $request->meta['pickup_point_code'] ?? '' );
				$delivery_point_index = $this->pickup_destination_index( $request, $pickup_code, ! $is_ecom );
				if ( $is_ecom && '' !== $delivery_point_index ) {
					$payload['ecom-data'] = array( 'delivery-point-index' => $delivery_point_index );
				} elseif ( ! $is_ecom ) {
					$payload['address-type-to'] = 'DEMAND';
					$payload['index-to'] = $delivery_point_index;
					$payload['region-to'] = $request->recipient_address->region_name;
					$payload['place-to'] = $this->place_to( $request );
				}

				$shelf_life_days = (int) ( $request->services['shelf_life_days'] ?? 30 );
				$payload['shelf-life-days'] = max( 15, min( 60, $shelf_life_days ) );
			}

			if ( $is_mmo ) {
				$payload['add-to-mmo'] = true;
				$payload['group-name'] = $order_num;
			}

			if ( $send_goods ) {
				$payload['goods'] = array( 'items' => $this->goods_items( $place, $combine_goods, $combined_name ) );
			}

			$result[] = array_filter(
				$payload,
				static fn ( mixed $value ): bool => ! ( is_string( $value ) && '' === trim( $value ) )
			);
		}

		return $result;
	}

	/**
	 * @return array<int,string>
	 */
	public function validate( ShipmentCreateRequest $request ): array {
		$errors = array();
		$places = $request->places;

		if ( array() === $places ) {
			$errors[] = 'Добавьте хотя бы одно грузоместо.';
		}

		if ( count( $places ) > 1 ) {
			if ( count( $places ) > 50 ) {
				$errors[] = 'Для ММО должно быть от 2 до 50 мест.';
			}
			$product = $this->products->by_object_code( (string) ( $request->meta['tariff_object'] ?? '' ), $request->delivery_type );
			if ( empty( $product['mmo_allowed'] ) ) {
				$errors[] = 'Выбранный тариф не поддерживает ММО.';
			}
		}

		$total_weight = 0;
		foreach ( $places as $index => $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$total_weight += $place->weight_g;
			$errors = array_merge( $errors, $this->place_errors( $place, $index + 1 ) );
			if ( $place->weight_g > self::MAX_PLACE_WEIGHT_G ) {
				$errors[] = 'Вес одного места не должен превышать 31,5 кг.';
			}
		}

		if ( $total_weight > self::MAX_MMO_WEIGHT_G ) {
			$errors[] = 'Общий вес ММО не должен превышать 300 кг.';
		}

		if ( '' === trim( (string) ( $request->recipient['name'] ?? '' ) ) ) {
			$errors[] = 'ФИО получателя обязательно.';
		}
		if ( '' === $this->normalize_phone( (string) ( $request->recipient['phone'] ?? '' ) ) ) {
			$errors[] = 'Телефон получателя обязателен.';
		}
		if ( '' === trim( (string) ( $request->meta['postoffice_code'] ?? $request->meta['from_postcode'] ?? '' ) ) ) {
			$errors[] = 'Индекс места приема обязателен.';
		}
		if ( '' === trim( (string) ( $request->meta['tariff_object'] ?? $request->meta['selected_tariff_object'] ?? '' ) ) ) {
			$errors[] = 'Выберите тариф для создания отправления.';
		}

		if ( DeliveryType::COURIER === $request->delivery_type ) {
			if ( '' === trim( $request->recipient_address->postcode ) ) {
				$errors[] = 'Индекс получателя обязателен.';
			}
			if ( '' === trim( $this->place_to( $request ) ) ) {
				$errors[] = 'Населенный пункт получателя обязателен.';
			}
			if ( empty( $request->meta['allow_failed_normalization_preview'] ) && true !== ( $request->meta['normalization_valid'] ?? false ) ) {
				$errors[] = 'Адрес курьерской доставки нужно успешно обработать через Почту России перед созданием отправления.';
			}
		}

		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			$pickup_code = $request->pickup_point?->point_code ?? (string) ( $request->meta['pickup_point_code'] ?? '' );
			$is_ecom = ! empty( $request->meta['tariff_is_ecom'] );
			$delivery_point_index = $this->pickup_destination_index( $request, $pickup_code, ! $is_ecom );
			if ( '' === trim( $pickup_code ) ) {
				$errors[] = 'Код ПВЗ/почтомата обязателен.';
			}
			if ( empty( $request->meta['pickup_point_found'] ) ) {
				$errors[] = 'Выбранный ПВЗ/ОПС не найден в справочнике Почты России.';
			}
			if ( '' === trim( $delivery_point_index ) ) {
				$errors[] = 'Индекс выбранного ПВЗ/ОПС обязателен.';
			}
			if ( empty( $request->meta['tariff_is_ecom'] ) ) {
				if ( '' === trim( $request->recipient_address->region_name ) ) {
					$errors[] = 'Регион получателя обязателен для обычного ПВЗ/ОПС.';
				}
				if ( '' === trim( $this->place_to( $request ) ) ) {
					$errors[] = 'Населенный пункт получателя обязателен для обычного ПВЗ/ОПС.';
				}
			}
		}

		return array_values( array_unique( $errors ) );
	}

	private function normalize_phone( string $phone ): string {
		return preg_replace( '/\D+/', '', $phone ) ?? '';
	}

	/**
	 * @return array<string,string>
	 */
	private function split_name( string $name ): array {
		$parts = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		if ( count( $parts ) < 2 ) {
			return array();
		}

		return array_filter(
			array(
				'surname' => (string) ( $parts[0] ?? '' ),
				'given-name' => (string) ( $parts[1] ?? '' ),
				'middle-name' => (string) ( $parts[2] ?? '' ),
			),
			static fn ( string $value ): bool => '' !== trim( $value )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function goods_items( ShipmentPlace $place, bool $combine, string $combined_name ): array {
		if ( $combine || array() === $place->items ) {
			return array(
				array(
					'description' => $combined_name,
					'quantity' => 1,
					'value' => $place->declared_value->get_kopecks() > 0 ? $place->declared_value->get_kopecks() : 0,
				),
			);
		}

		$items = array();
		foreach ( $place->items as $item ) {
			if ( ! $item instanceof PackageItem ) {
				continue;
			}
			$items[] = array(
				'description' => $item->name,
				'quantity' => max( 1, $item->quantity ),
				'value' => $item->unit_price->get_kopecks(),
				'weight' => max( 0, $item->weight_g ),
				'vat-rate' => 'WITHOUT_VAT',
			);
		}

		return $items;
	}

	private function order_num( ShipmentCreateRequest $request ): string {
		$order_num = trim( (string) ( $request->meta['order_num'] ?? '' ) );

		return '' !== $order_num ? $order_num : (string) $request->order_id;
	}

	private function place_to( ShipmentCreateRequest $request ): string {
		return trim( $request->recipient_address->settlement ) ?: trim( $request->recipient_address->city );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function courier_address_fields( ShipmentCreateRequest $request ): array {
		$normalized = is_array( $request->meta['normalized_address'] ?? null ) ? $request->meta['normalized_address'] : array();
		$fields = is_array( $normalized['fields'] ?? null ) ? $normalized['fields'] : array();
		if ( ! empty( $normalized['success'] ) && array() !== $fields ) {
			$result = array();
			foreach ( $fields as $field_key => $field_value ) {
				$result[ (string) $field_key ] = $field_value;
			}
			$result['address-type-to'] = (string) ( $result['address-type-to'] ?? 'DEFAULT' );

			return $result;
		}

		return array(
			'address-type-to' => 'DEFAULT',
			'index-to' => $request->recipient_address->postcode,
			'region-to' => $request->recipient_address->region_name,
			'place-to' => $this->place_to( $request ),
		);
	}

	private function pickup_destination_index( ShipmentCreateRequest $request, string $pickup_code = '', bool $allow_recipient_fallback = true ): string {
		$explicit = preg_replace( '/\D+/', '', (string) ( $request->meta['pickup_point_postcode'] ?? $request->meta['pickup_postcode'] ?? '' ) ) ?? '';
		if ( 1 === preg_match( '/^\d{6}$/', $explicit ) ) {
			return $explicit;
		}

		if ( 1 === preg_match( '/^(\d{6})/', trim( $pickup_code ), $matches ) ) {
			return (string) $matches[1];
		}

		if ( ! $allow_recipient_fallback ) {
			return '';
		}

		$postcode = preg_replace( '/\D+/', '', $request->recipient_address->postcode ) ?? '';
		return 1 === preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	/**
	 * @return array<int,string>
	 */
	private function place_errors( ShipmentPlace $place, int $visible_index ): array {
		$label = 'Место ' . $visible_index . ': ';
		$errors = array();

		if ( $place->place_number <= 0 ) {
			$errors[] = $label . 'номер места должен быть больше 0.';
		}
		if ( $place->weight_g <= 0 ) {
			$errors[] = $label . 'вес обязателен.';
		}
		if ( $place->length_cm <= 0 ) {
			$errors[] = $label . 'длина обязательна.';
		}
		if ( $place->width_cm <= 0 ) {
			$errors[] = $label . 'ширина обязательна.';
		}
		if ( $place->height_cm <= 0 ) {
			$errors[] = $label . 'высота обязательна.';
		}

		return $errors;
	}
}
