<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekSmsReleaseAvailabilityService {
	public const SMS_SERVICE_UID = 'ffb40421-4761-11e8-80c9-00155d668927';
	private const PUBLIC_FAILURE = 'Не удалось подтвердить возможность выдачи груза по СМС.';

	/** @var array<string,PekSmsReleaseResult> */
	private array $cache = array();

	public function __construct(
		private PekApiClient $api,
		private PekPrivateAccessTokenService $tokens,
		private PekSettings $settings
	) {
	}

	public function check( string $counterpart_guid, string $sender_branch_id, string $receiver_branch_id, int $declared_value_kopecks ): PekSmsReleaseResult {
		$key = hash( 'sha256', implode( '|', array( $counterpart_guid, $sender_branch_id, $receiver_branch_id, (string) min( $declared_value_kopecks, 1000000000 ) ) ) );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		try {
			$geography = $this->api->check_no_calc_services( $sender_branch_id, $receiver_branch_id );
			if ( ! $this->has_sms_geography( $geography ) ) {
				return $this->cache[ $key ] = $this->fail();
			}
			$services = $this->api->connected_services( $this->tokens->token(), $counterpart_guid );
			$api_limit = $this->api_limit_kopecks( $services );
			if ( ! in_array( PekSettings::LTL_PRODUCT_TYPE, array_map( 'intval', is_array( $services['availableTypesOfDelivery'] ?? null ) ? $services['availableTypesOfDelivery'] : array() ), true ) ) {
				return $this->cache[ $key ] = $this->fail();
			}
			$configured = $this->settings->sms_release_limit_rub() * 100;
			$effective = min( $configured, $api_limit );
			if ( $effective <= 0 || $declared_value_kopecks <= 0 || $declared_value_kopecks > $effective ) {
				return $this->cache[ $key ] = $this->fail();
			}

			return $this->cache[ $key ] = new PekSmsReleaseResult( true, $effective, true, true );
		} catch ( \Throwable ) {
			return $this->cache[ $key ] = $this->fail();
		}
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function has_sms_geography( array $rows ): bool {
		foreach ( $rows as $row ) {
			$condition = is_array( $row['specialCondition'] ?? null ) ? $row['specialCondition'] : array();
			if ( self::SMS_SERVICE_UID === strtolower( (string) ( $condition['UID'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $services */
	private function api_limit_kopecks( array $services ): int {
		$candidates = array();
		$scan = function ( mixed $value ) use ( &$scan, &$candidates ): void {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $child ) {
					if ( 'CODMaxSum' === (string) $key && is_numeric( $child ) && (float) $child > 0 ) {
						$candidates[] = (int) round( (float) $child * 100 );
					}
					$scan( $child );
				}
			}
		};
		$scan( $services );
		if ( array() === $candidates ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_missing' ) );
		}

		return min( $candidates );
	}

	private function fail(): PekSmsReleaseResult {
		return new PekSmsReleaseResult( false, 0, false, false, self::PUBLIC_FAILURE );
	}
}
