<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostPassportPointNormalizer {
	public const CARRIER_KEY = 'russian_post';

	public function __construct( private ?RussianPostWorkTimeFormatter $work_time_formatter = null ) {
		$this->work_time_formatter ??= new RussianPostWorkTimeFormatter();
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>|null
	 */
	public function normalize( array $item, string $import_type = 'ALL', string $seen_at = '' ): ?array {
		$lat = $item['latitude'] ?? null;
		$lng = $item['longitude'] ?? null;
		if ( null === $lat || null === $lng || '' === (string) $lat || '' === (string) $lng ) {
			return null;
		}

		$address = is_array( $item['address'] ?? null ) ? $item['address'] : array();
		$fias = is_array( $item['addressFias'] ?? null ) ? $item['addressFias'] : array();
		$ecom = is_array( $item['ecomOptions'] ?? null ) ? $item['ecomOptions'] : array();
		$postcode = trim( (string) ( $address['index'] ?? '' ) );
		$address_full = trim( (string) ( $fias['ads'] ?? '' ) );
		if ( '' === $address_full ) {
			$address_full = $this->join_non_empty(
				array(
					$address['region'] ?? '',
					$address['place'] ?? '',
					$address['street'] ?? '',
					$address['house'] ?? ( $address['building'] ?? '' ),
				)
			);
		}

		$type = $this->point_type( (string) ( $item['type'] ?? '' ), $import_type );
		$identity = self::CARRIER_KEY . '|' . $postcode . '|' . round( (float) $lat, 7 ) . '|' . round( (float) $lng, 7 ) . '|' . $address_full;
		$source_hash = sha1( $identity );
		$point_code = '' !== $postcode ? $postcode . '-' . substr( $source_hash, 0, 10 ) : $source_hash;
		$work_time = $this->work_time_formatter->format( $item['workTime'] ?? null );

		return array(
			'carrier_key' => self::CARRIER_KEY,
			'point_code' => $point_code,
			'point_type' => $type,
			'country_code' => 'RU',
			'region_name' => (string) ( $address['region'] ?? '' ),
			'city_name' => trim( (string) ( $address['place'] ?? '' ) ),
			'address' => $address_full,
			'postcode' => $postcode,
			'latitude' => (float) $lat,
			'longitude' => (float) $lng,
			'work_time' => $work_time,
			'comment' => (string) ( $ecom['getto'] ?? '' ),
			'active' => 1,
			'source_hash' => $source_hash,
			'last_seen_at' => '' !== $seen_at ? $seen_at : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ),
			'brand_name' => (string) ( $item['brandName'] ?? ( $ecom['brandName'] ?? '' ) ),
			'description' => (string) ( $ecom['getto'] ?? '' ),
			'street' => (string) ( $address['street'] ?? '' ),
			'house' => (string) ( $address['house'] ?? ( $address['building'] ?? '' ) ),
			'fias_location_guid' => (string) ( $fias['locationGarCode'] ?? '' ),
			'fias_address_guid' => (string) ( $fias['addGarCode'] ?? '' ),
			'gar_region_id' => (string) ( $fias['regGarId'] ?? '' ),
			'geohash' => $this->simple_geohash( (float) $lat, (float) $lng ),
			'ecom_options_json' => $ecom,
			'services_json' => $item['services'] ?? null,
			'phones_json' => $item['phones'] ?? null,
			'images_json' => $item['images'] ?? null,
			'weight_limit_grams' => isset( $ecom['weightLimit'] ) && is_numeric( $ecom['weightLimit'] ) ? (int) round( (float) $ecom['weightLimit'] * 1000 ) : null,
			'size_limit_json' => array_filter( array( 'typesizeId' => $ecom['typesizeId'] ?? null, 'typesizeVal' => $ecom['typesizeVal'] ?? null ) ),
			'accepts_cash' => $ecom['cashPayment'] ?? null,
			'accepts_card' => $ecom['cardPayment'] ?? null,
			'partial_redemption' => $ecom['partialRedemption'] ?? null,
			'return_available' => $ecom['returnAvailable'] ?? null,
			'fitting_available' => $ecom['withFitting'] ?? null,
			'contents_checking' => $ecom['contentsChecking'] ?? null,
			'functionality_checking' => $ecom['functionalityChecking'] ?? null,
		);
	}

	private function point_type( string $raw_type, string $import_type ): string {
		$haystack = $raw_type . ' ' . strtoupper( $import_type );
		if ( str_contains( $haystack, 'Почтомат' ) || str_contains( $haystack, 'APS' ) ) {
			return 'APS';
		}
		if ( str_contains( $haystack, 'ПВЗ' ) || str_contains( $haystack, 'PVZ' ) ) {
			return 'PVZ';
		}

		return 'OPS';
	}

	/**
	 * @param array<int,mixed> $parts
	 */
	private function join_non_empty( array $parts ): string {
		return implode( ', ', array_values( array_filter( array_map( static fn( mixed $part ): string => trim( (string) $part ), $parts ), static fn( string $part ): bool => '' !== $part ) ) );
	}

	private function simple_geohash( float $lat, float $lng ): string {
		return substr( sha1( round( $lat, 4 ) . ':' . round( $lng, 4 ) ), 0, 12 );
	}
}
