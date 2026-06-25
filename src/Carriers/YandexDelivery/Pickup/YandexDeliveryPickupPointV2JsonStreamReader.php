<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointV2JsonStreamReader {
	private const POINT_ARRAY_KEYS = array( 'points', 'pickup_points', 'pickupPoints', 'items', 'results' );
	private const CHUNK_SIZE = 8192;

	/** @return \Generator<int,array<string,mixed>> */
	public function read_points( string $filename ): \Generator {
		$handle = fopen( $filename, 'rb' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Yandex Delivery pickup v2 JSON file is not readable.' );
		}

		try {
			$in_array = false;
			$object_depth = 0;
			$object_json = '';
			$in_string = false;
			$escaped = false;
			$string_buffer = '';
			$last_string = '';
			$expecting_value_for = '';
			$pre_depth = 0;

			while ( false !== ( $chunk = fread( $handle, self::CHUNK_SIZE ) ) && '' !== $chunk ) {
				$length = strlen( $chunk );
				for ( $i = 0; $i < $length; ++$i ) {
					$char = $chunk[ $i ];

					if ( ! $in_array ) {
						if ( $in_string ) {
							if ( $escaped ) {
								$string_buffer .= $char;
								$escaped = false;
								continue;
							}
							if ( '\\' === $char ) {
								$escaped = true;
								continue;
							}
							if ( '"' === $char ) {
								$in_string = false;
								$last_string = $string_buffer;
								$string_buffer = '';
								continue;
							}
							$string_buffer .= $char;
							continue;
						}

						if ( '"' === $char ) {
							$in_string = true;
							$string_buffer = '';
							continue;
						}
						if ( ':' === $char ) {
							$expecting_value_for = $last_string;
							continue;
						}
						if ( '[' === $char ) {
							if ( 0 === $pre_depth || in_array( $expecting_value_for, self::POINT_ARRAY_KEYS, true ) ) {
								$in_array = true;
								$expecting_value_for = '';
								continue;
							}
							++$pre_depth;
							$expecting_value_for = '';
							continue;
						}
						if ( '{' === $char ) {
							++$pre_depth;
							$expecting_value_for = '';
							continue;
						}
						if ( '}' === $char || ']' === $char ) {
							$pre_depth = max( 0, $pre_depth - 1 );
							$expecting_value_for = '';
							continue;
						}
						if ( ! ctype_space( $char ) && ',' !== $char ) {
							$expecting_value_for = '';
						}
						continue;
					}

					if ( 0 === $object_depth ) {
						if ( '{' === $char ) {
							$object_depth = 1;
							$object_json = '{';
							$in_string = false;
							$escaped = false;
						}
						continue;
					}

					$object_json .= $char;
					if ( $in_string ) {
						if ( $escaped ) {
							$escaped = false;
							continue;
						}
						if ( '\\' === $char ) {
							$escaped = true;
							continue;
						}
						if ( '"' === $char ) {
							$in_string = false;
						}
						continue;
					}
					if ( '"' === $char ) {
						$in_string = true;
						continue;
					}
					if ( '{' === $char ) {
						++$object_depth;
						continue;
					}
					if ( '}' === $char ) {
						--$object_depth;
						if ( 0 === $object_depth ) {
							$decoded = json_decode( $object_json, true );
							if ( is_array( $decoded ) ) {
								yield $decoded;
							}
							$object_json = '';
						}
					}
				}
			}
		} finally {
			fclose( $handle );
		}
	}
}