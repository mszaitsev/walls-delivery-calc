<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryCheckoutPickupPointFormatter {
	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function format( array $row ): array {
		$station_id = $this->station_id( $row );
		$type = trim( (string) ( $row['type'] ?? '' ) );
		$type_label = 'terminal' === $type ? 'Терминал' : 'Пункт выдачи';
		$marker_type = 'terminal' === $type ? 'terminal' : 'pickup';
		$address = (string) ( $row['full_address'] ?? '' );
		$name = (string) ( $row['name'] ?? '' );
		$presentation = $this->presentation( $row );
		$point_title = $presentation['title'];
		$presentation_comment = $presentation['comment'];
		$point_name = '' !== trim( $name ) ? trim( $name ) : $point_title;
		$snapshot = array(
			'id' => YandexDeliverySettings::CARRIER_KEY . ':' . $station_id,
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':pickup',
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => '',
			'display_title' => $point_title,
			'presentation_comment' => $presentation_comment,
			'marker_type' => $marker_type,
			'point_name' => $point_name,
			'address' => $address,
			'city' => (string) ( $row['locality'] ?? '' ),
			'region' => (string) ( $row['region'] ?? '' ),
			'postcode' => (string) ( $row['postal_code'] ?? '' ),
			'lat' => $this->coordinate( $row['latitude'] ?? null ),
			'lng' => $this->coordinate( $row['longitude'] ?? null ),
			'work_time' => (string) ( $row['schedule_text'] ?? '' ),
			'description' => (string) ( $row['instruction'] ?? '' ),
			'storage_notice' => '',
			'operator_id' => (string) ( $row['operator_id'] ?? '' ),
			'operator_station_id' => (string) ( $row['operator_station_id'] ?? '' ),
			'yandex_geo_id' => is_numeric( $row['yandex_geo_id'] ?? null ) ? (int) $row['yandex_geo_id'] : null,
		);

		return array(
			'id' => $snapshot['id'],
			'carrier' => YandexDeliverySettings::CARRIER_KEY,
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'card_title' => $point_title,
			'display_code' => '',
			'display_title' => $snapshot['display_title'],
			'presentation_comment' => $presentation_comment,
			'marker_type' => $marker_type,
			'title' => $point_title,
			'point_name' => $point_name,
			'address' => $address,
			'point_address' => $address,
			'city' => $snapshot['city'],
			'city_name' => $snapshot['city'],
			'region' => $snapshot['region'],
			'region_name' => $snapshot['region'],
			'postal_code' => $snapshot['postcode'],
			'postcode' => $snapshot['postcode'],
			'point_postcode' => $snapshot['postcode'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'latitude' => $snapshot['lat'],
			'longitude' => $snapshot['lng'],
			'work_time' => $snapshot['work_time'],
			'schedule' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'operator_id' => $snapshot['operator_id'],
			'snapshot' => $snapshot,
		);
	}

	/** @param array<string,mixed> $row */
	private function station_id( array $row ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '', trim( (string) ( $row['platform_station_id'] ?? '' ) ) ) ?? '', 0, 80 );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{title:string,comment:string}
	 */
	private function presentation( array $row ): array {
		$operator_id = $this->normalized( $row['operator_id'] ?? '' );
		$type = $this->normalized( $row['type'] ?? '' );
		$name = trim( (string) ( $row['name'] ?? '' ) );
		$terminal_warning = 'Срок хранения посылки - 2-3 дня!';

		if ( 'market_l4g' === $operator_id && 'pickup_point' === $type && $this->name_matches( $name, 'Пункт выдачи заказов Яндекс Маркета' ) ) {
			return array( 'title' => 'Пункт выдачи Яндекс.Маркет', 'comment' => '' );
		}
		if ( 'market_l4g' === $operator_id && 'pickup_point' === $type && $this->name_matches( $name, 'Пункт выдачи заказов партнёра' ) ) {
			return array( 'title' => 'Партнёрский пункт выдачи', 'comment' => '' );
		}
		if ( '5post' === $operator_id ) {
			return array( 'title' => '5 Post (Пятерочка)', 'comment' => 'Цена будет пересчитана, иногда сюда получается дороже!' );
		}
		if ( 'market_l4g' === $operator_id && 'terminal' === $type ) {
			return array( 'title' => 'Постамат Яндекса', 'comment' => $terminal_warning );
		}

		return array( 'title' => 'Выдача посылок Яндекс.Доставки', 'comment' => '' );
	}

	private function name_matches( string $name, string $expected ): bool {
		$name = trim( $name );
		$expected = trim( $expected );

		return $name === $expected || $this->normalized( $name ) === $this->normalized( $expected );
	}

	private function normalized( mixed $value ): string {
		$value = trim( (string) $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	private function coordinate( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
