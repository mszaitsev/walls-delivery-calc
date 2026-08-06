<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Common\MoneyParser;

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
			if ( ! in_array( PekSettings::LTL_PRODUCT_TYPE, array_map( 'intval', is_array( $services['availableTypesOfDelivery'] ?? null ) ? $services['availableTypesOfDelivery'] : array() ), true ) ) {
				return $this->cache[ $key ] = $this->fail();
			}
			$api_limit = $this->api_limit_kopecks( $services, $sender_branch_id, $receiver_branch_id );
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
	private function api_limit_kopecks( array $services, string $sender_branch_id, string $receiver_branch_id ): int {
		unset( $sender_branch_id, $receiver_branch_id );
		$rows = is_array( $services['specialConditionsWithParams'] ?? null ) ? $services['specialConditionsWithParams'] : array();
		$matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$condition = is_array( $row['specialCondition'] ?? null ) ? $row['specialCondition'] : array();
			if ( strtolower( self::SMS_SERVICE_UID ) !== strtolower( (string) ( $condition['UID'] ?? '' ) ) ) {
				continue;
			}
			$matches[] = $row;
		}
		if ( 1 !== count( $matches ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_missing' ) );
		}

		return $this->cod_limit_from_sms_row( $matches[0] );
	}

	/** @param array<string,mixed> $row */
	private function cod_limit_from_sms_row( array $row ): int {
		foreach ( is_array( $row['params'] ?? null ) ? $row['params'] : ( is_array( $row['parameters'] ?? null ) ? $row['parameters'] : array() ) as $param ) {
			if ( ! is_array( $param ) || 'CODMaxSum' !== (string) ( $param['key'] ?? '' ) || 'Money' !== (string) ( $param['type'] ?? '' ) ) {
				continue;
			}
			$values = $this->parameter_values( $param );
			if ( 1 !== count( $values ) ) {
				break;
			}
			$kopecks = MoneyParser::numeric_to_kopecks( $values[0] );
			if ( null !== $kopecks && $kopecks > 0 ) {
				return $kopecks;
			}
		}

		throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_malformed' ) );
	}

	/** @param array<string,mixed> $row */
	/**
	 * @param array<string,mixed> $param
	 * @return array<int,int|float|string|bool>
	 */
	private function parameter_values( array $param ): array {
		if ( ! array_key_exists( 'values', $param ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_missing' ) );
		}
		$values = $param['values'];
		if ( is_int( $values ) || is_float( $values ) || is_string( $values ) || is_bool( $values ) ) {
			return array( $values );
		}
		if ( ! is_array( $values ) || ! array_is_list( $values ) || array() === $values ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_malformed' ) );
		}
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) && ! is_bool( $value ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_malformed' ) );
			}
		}

		return $values;
	}

	private function fail(): PekSmsReleaseResult {
		return new PekSmsReleaseResult( false, 0, false, false, self::PUBLIC_FAILURE );
	}
}
