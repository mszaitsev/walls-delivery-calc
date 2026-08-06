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
		$key = hash( 'sha256', implode( '|', array( $counterpart_guid, $sender_branch_id, $receiver_branch_id, (string) $declared_value_kopecks ) ) );
		if ( isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		try {
			$geography = $this->api->check_no_calc_services( $sender_branch_id, $receiver_branch_id );
			if ( ! $this->has_sms_geography( $geography ) ) {
				return $this->cache[ $key ] = $this->fail();
			}
			$services = $this->api->connected_services( $this->tokens->token(), $counterpart_guid );
			if ( ! in_array( PekSettings::LTL_PRODUCT_TYPE, $this->available_types( $services ), true ) ) {
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
		$matches = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || ! is_array( $row['specialCondition'] ?? null ) || array_is_list( $row['specialCondition'] ) || ! is_string( $row['specialCondition']['UID'] ?? null ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_geography_malformed' ) );
			}
			if ( strtolower( self::SMS_SERVICE_UID ) === strtolower( trim( $row['specialCondition']['UID'] ) ) ) {
				++$matches;
			}
		}

		if ( $matches > 1 ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_geography_duplicate' ) );
		}

		return 1 === $matches;
	}

	/** @param array<string,mixed> $services */
	/** @return array<int,int> */
	private function available_types( array $services ): array {
		if ( ! array_key_exists( 'availableTypesOfDelivery', $services ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_missing' ) );
		}
		$types = $services['availableTypesOfDelivery'];
		if ( ! is_array( $types ) || ! array_is_list( $types ) || array() === $types ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_malformed' ) );
		}
		$result = array();
		foreach ( $types as $type ) {
			if ( ! is_int( $type ) || $type <= 0 ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_available_types_malformed' ) );
			}
			$result[] = $type;
		}

		return array_values( array_unique( $result ) );
	}

	/** @param array<string,mixed> $services */
	private function api_limit_kopecks( array $services, string $sender_branch_id, string $receiver_branch_id ): int {
		unset( $sender_branch_id, $receiver_branch_id );
		if ( ! array_key_exists( 'specialConditionsWithParams', $services ) || ! is_array( $services['specialConditionsWithParams'] ) || ! array_is_list( $services['specialConditionsWithParams'] ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_special_conditions_malformed' ) );
		}
		$rows = $services['specialConditionsWithParams'];
		$matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array_is_list( $row ) || ! is_array( $row['specialCondition'] ?? null ) || array_is_list( $row['specialCondition'] ) || ! is_string( $row['specialCondition']['UID'] ?? null ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_special_conditions_malformed' ) );
			}
			if ( strtolower( self::SMS_SERVICE_UID ) !== strtolower( $row['specialCondition']['UID'] ) ) {
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
		if ( ! is_array( $row['params'] ?? null ) || ! array_is_list( $row['params'] ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_params_malformed' ) );
		}
		$matches = array();
		foreach ( $row['params'] as $param ) {
			if ( ! is_array( $param ) || array_is_list( $param ) ) {
				throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_params_malformed' ) );
			}
			if ( 'CODMaxSum' !== (string) ( $param['key'] ?? '' ) || 'Money' !== (string) ( $param['type'] ?? '' ) ) {
				continue;
			}
			$matches[] = $param;
		}
		if ( 1 !== count( $matches ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_malformed' ) );
		}
		$value = $this->money_scalar_value( $matches[0] );
		$kopecks = MoneyParser::numeric_to_kopecks( $value );
		if ( null !== $kopecks && $kopecks > 0 ) {
			return $kopecks;
		}

		throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_cod_limit_malformed' ) );
	}

	/** @param array<string,mixed> $param */
	private function money_scalar_value( array $param ): int|float|string {
		if ( ! array_key_exists( 'values', $param ) ) {
			throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_missing' ) );
		}
		$value = $param['values'];
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) || is_string( $value ) ) {
			return $value;
		}

		throw new PekApiException( self::PUBLIC_FAILURE, array( 'error_code' => 'pek_sms_param_values_malformed' ) );
	}

	private function fail(): PekSmsReleaseResult {
		return new PekSmsReleaseResult( false, 0, false, false, self::PUBLIC_FAILURE );
	}
}
