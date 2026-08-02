<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekConnectionDiagnosticService {
	public function __construct(
		private PekSettings $settings,
		private PekCredentials $credentials,
		private PekApiClient $api
	) {
	}

	/** @return array<string,mixed> */
	public function run(): array {
		$result = array(
			'success' => false,
			'checked_at' => $this->now(),
			'credentials_present' => $this->credentials->is_complete(),
			'ltl_product_type' => false,
			'countries_found' => array(),
			'planned_countries_missing' => array(),
			'classifier_mismatches' => array(),
			'legal_forms_available' => false,
			'message' => '',
		);
		if ( ! $result['credentials_present'] ) {
			$result['message'] = 'Не заданы логин или API key ПЭК.';
			$this->settings->save_diagnostic_result( $result );
			return $result;
		}
		try {
			$types = $this->api->types_of_delivery_all();
			foreach ( $types as $type ) {
				if ( PekSettings::LTL_PRODUCT_TYPE === (int) ( $type['type'] ?? 0 ) ) {
					$result['ltl_product_type'] = true;
				}
			}
			$countries = $this->api->branches_country();
			$found = array_fill_keys( PekSettings::PLANNED_COUNTRIES, false );
			$mismatches = array();
			foreach ( $countries as $country ) {
				$code = strtoupper( trim( (string) ( $country['shortName'] ?? '' ) ) );
				if ( '' === $code || ! array_key_exists( $code, PekSettings::COUNTRY_CLASSIFIER_CODES ) ) {
					continue;
				}
				$classifier = trim( (string) ( $country['codeByClassifier'] ?? '' ) );
				$expected = PekSettings::COUNTRY_CLASSIFIER_CODES[ $code ];
				if ( $classifier === $expected ) {
					$found[ $code ] = true;
					continue;
				}
				$mismatches[] = array(
					'country' => $code,
					'expected' => $expected,
					'actual' => $classifier,
				);
			}
			$result['countries_found'] = array_values(
				array_filter(
					PekSettings::PLANNED_COUNTRIES,
					static fn( string $code ): bool => true === ( $found[ $code ] ?? false )
				)
			);
			$result['planned_countries_missing'] = array_values( array_diff( PekSettings::PLANNED_COUNTRIES, $result['countries_found'] ) );
			$result['classifier_mismatches'] = $mismatches;
			$result['legal_forms_available'] = array() !== $this->api->legal_form_types();
			$result['success'] = (bool) $result['ltl_product_type'] && (bool) $result['legal_forms_available'] && in_array( 'RU', $result['countries_found'], true );
			$result['message'] = $result['success'] ? 'Подключение ПЭК проверено. RU доступна, LTL type=3 найден.' : 'Проверка ПЭК завершена с предупреждениями.';
		} catch ( PekApiException $exception ) {
			$result['message'] = 'Не удалось проверить ПЭК: ' . $exception->getMessage();
			$result['error_code'] = (string) ( $exception->context()['error_code'] ?? 'pek_diagnostic_failed' );
		}
		$this->settings->save_diagnostic_result( $result );

		return $result;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
