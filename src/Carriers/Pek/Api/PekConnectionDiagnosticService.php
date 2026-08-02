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
			'connection_ok' => false,
			'all_checks_passed' => false,
			'checked_at' => $this->now(),
			'credentials_present' => $this->credentials->is_complete(),
			'ltl_product_type' => null,
			'countries_found' => array(),
			'planned_countries_missing' => array(),
			'classifier_mismatches' => array(),
			'legal_forms_available' => null,
			'checks' => array(),
			'message' => '',
		);
		if ( ! $result['credentials_present'] ) {
			$result['message'] = 'Не заданы логин или API key ПЭК.';
			$this->settings->save_diagnostic_result( $result );
			return $result;
		}

		$products_ltl_found = false;
		$products = $this->run_check(
			'products',
			'/typesOfDelivery/all/',
			'GET',
			false,
			function () use ( &$products_ltl_found ): string {
				$types = $this->api->types_of_delivery_all();
				foreach ( $types as $type ) {
					if ( PekSettings::LTL_PRODUCT_TYPE === (int) ( $type['type'] ?? 0 ) ) {
						$products_ltl_found = true;
					}
				}

				return $products_ltl_found ? 'LTL type=3 найден.' : 'Справочник продуктов доступен, но LTL type=3 не найден.';
			}
		);
		$result['checks']['products'] = $products;
		$result['ltl_product_type'] = $products['success'] ? $products_ltl_found : null;

		$countries_found = array();
		$planned_missing = array();
		$mismatches = array();
		$countries = $this->run_check(
			'countries',
			'/branches/country/',
			'POST',
			false,
			function () use ( &$countries_found, &$planned_missing, &$mismatches ): string {
				$found = array_fill_keys( PekSettings::PLANNED_COUNTRIES, false );
				foreach ( $this->api->branches_country() as $country ) {
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
				$countries_found = array_values(
					array_filter(
						PekSettings::PLANNED_COUNTRIES,
						static fn( string $code ): bool => true === ( $found[ $code ] ?? false )
					)
				);
				$planned_missing = array_values( array_diff( PekSettings::PLANNED_COUNTRIES, $countries_found ) );

				return in_array( 'RU', $countries_found, true ) ? 'RU подтверждена classifier 643.' : 'Справочник стран доступен, RU не подтверждена classifier 643.';
			}
		);
		$result['checks']['countries'] = $countries;
		if ( $countries['success'] ) {
			$result['countries_found'] = $countries_found;
			$result['planned_countries_missing'] = $planned_missing;
			$result['classifier_mismatches'] = $mismatches;
		}

		$legal_forms_available = false;
		$legal_forms = $this->run_check(
			'legal_forms',
			'/counterparts/legalformtypes/',
			'POST',
			false,
			function () use ( &$legal_forms_available ): string {
				$legal_forms_available = array() !== $this->api->legal_form_types();

				return $legal_forms_available ? 'Справочник юридических форм доступен.' : 'Справочник юридических форм доступен, но пуст.';
			}
		);
		$result['checks']['legal_forms'] = $legal_forms;
		$result['legal_forms_available'] = $legal_forms['success'] ? $legal_forms_available : null;

		$warehouse = $this->settings->sender_warehouse();
		$warehouse_id = trim( (string) ( $warehouse['warehouseId'] ?? '' ) );
		$warehouse_source = trim( (string) ( $warehouse['source'] ?? '' ) );
		$warehouse_match = null;
		if ( '' === $warehouse_id ) {
			$result['checks']['warehouse_api'] = $this->skipped_check( '/branches/all/', 'POST', false, 'Склад самопривоза ещё не выбран.' );
			$result['checks']['warehouse_match'] = $this->skipped_match_check( 'Склад самопривоза ещё не выбран.' );
		} else {
			$result['checks']['warehouse_api'] = $this->run_check(
				'warehouse_api',
				'/branches/all/',
				'POST',
				true,
				function () use ( $warehouse_id, &$warehouse_match ): string {
					$branches = $this->api->branches_all_for_warehouse( $warehouse_id );
					$warehouse_match = PekSenderWarehouseService::find_warehouse_in_branches_response( $branches, $warehouse_id );

					return 'Метод списка филиалов ПЭК доступен.';
				}
			);
			$result['checks']['warehouse_match'] = true === ( $result['checks']['warehouse_api']['success'] ?? false )
				? $this->warehouse_match_check( $warehouse_id, $warehouse_source, is_array( $warehouse_match ) ? $warehouse_match : array() )
				: $this->skipped_match_check( 'Сопоставление склада пропущено: метод /branches/all/ недоступен.' );
		}

		$result['connection_ok'] = $this->connection_ok( $result['checks'] );
		$result['all_checks_passed'] = $this->all_checks_passed( $result['checks'] );
		$result['success'] = $result['connection_ok'];
		$result['message'] = $this->summary_message( $result );
		$this->settings->save_diagnostic_result( $result );

		return $result;
	}

	/** @return array<string,mixed> */
	private function run_check( string $key, string $endpoint, string $method, bool $required, callable $callback ): array {
		try {
			$message = (string) $callback();

			return array(
				'endpoint' => $endpoint,
				'method' => strtoupper( $method ),
				'status' => 'passed',
				'success' => true,
				'skipped' => false,
				'required' => $required,
				'affects_connection' => true,
				'affects_all_checks' => true,
				'error_code' => '',
				'http_status' => 200,
				'message' => '' !== $message ? $message : 'Успешно.',
			);
		} catch ( PekApiException $exception ) {
			$context = $exception->context();

			return array(
				'endpoint' => (string) ( $context['endpoint'] ?? $endpoint ),
				'method' => strtoupper( $method ),
				'status' => 'failed',
				'success' => false,
				'skipped' => false,
				'required' => $required,
				'affects_connection' => true,
				'affects_all_checks' => true,
				'error_code' => (string) ( $context['error_code'] ?? 'pek_diagnostic_' . $key . '_failed' ),
				'http_status' => isset( $context['http_status'] ) ? (int) $context['http_status'] : null,
				'message' => $exception->getMessage(),
			);
		}
	}

	/** @return array<string,mixed> */
	private function skipped_check( string $endpoint, string $method, bool $required, string $message ): array {
		return array(
			'endpoint' => $endpoint,
			'method' => strtoupper( $method ),
			'status' => 'skipped',
			'success' => false,
			'skipped' => true,
			'required' => $required,
			'affects_connection' => false,
			'affects_all_checks' => false,
			'error_code' => '',
			'http_status' => null,
			'message' => $message,
		);
	}

	/** @param array<string,mixed> $match @return array<string,mixed> */
	private function warehouse_match_check( string $warehouse_id, string $source, array $match ): array {
		$found = true === ( $match['warehouse_found'] ?? false );
		$counters = array(
			'branches_checked' => (int) ( $match['branches_checked'] ?? 0 ),
			'divisions_checked' => (int) ( $match['divisions_checked'] ?? 0 ),
			'warehouses_checked' => (int) ( $match['warehouses_checked'] ?? 0 ),
		);
		$message = $found
			? 'Warehouse ID найден в /branches/all/.'
			: 'Сохранённый warehouse ID не найден в структуре ответа /branches/all/.';
		if ( '' !== $source ) {
			$message .= ' Склад был выбран из ' . $source . '.';
		}

		return array_merge(
			array(
				'endpoint' => '/branches/all/',
				'method' => 'POST',
				'status' => $found ? 'passed' : 'warning',
				'success' => $found,
				'skipped' => false,
				'required' => false,
				'informational' => true,
				'affects_connection' => false,
				'affects_all_checks' => false,
				'warehouse_found' => $found,
				'warehouse_id' => $warehouse_id,
				'matched_id' => $found ? (string) ( $match['matched_id'] ?? '' ) : '',
				'matched_field' => $found ? (string) ( $match['matched_field'] ?? '' ) : '',
				'info_code' => $found ? '' : 'pek_diagnostic_warehouse_not_matched',
				'http_status' => null,
				'message' => $message,
			),
			$counters
		);
	}

	/** @return array<string,mixed> */
	private function skipped_match_check( string $message ): array {
		return array(
			'endpoint' => '/branches/all/',
			'method' => 'POST',
			'status' => 'skipped',
			'success' => false,
			'skipped' => true,
			'required' => false,
			'informational' => true,
			'affects_connection' => false,
			'affects_all_checks' => false,
			'warehouse_found' => null,
			'warehouse_id' => '',
			'matched_id' => '',
			'matched_field' => '',
			'info_code' => '',
			'http_status' => null,
			'message' => $message,
			'branches_checked' => 0,
			'divisions_checked' => 0,
			'warehouses_checked' => 0,
		);
	}

	/** @param array<string,array<string,mixed>> $checks */
	private function connection_ok( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( is_array( $check ) && false !== ( $check['affects_connection'] ?? true ) && true === ( $check['success'] ?? false ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,array<string,mixed>> $checks */
	private function all_checks_passed( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || true === ( $check['skipped'] ?? false ) || false === ( $check['affects_all_checks'] ?? true ) ) {
				continue;
			}
			if ( true !== ( $check['success'] ?? false ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $result */
	private function summary_message( array $result ): string {
		if ( ! $result['connection_ok'] ) {
			return 'Не удалось подтвердить подключение ПЭК. Проверьте credentials и доступ к API.';
		}
		if ( $result['all_checks_passed'] ) {
			return 'Подключение ПЭК успешно проверено.';
		}
		$warehouse = is_array( $result['checks']['warehouse_api'] ?? null ) ? $result['checks']['warehouse_api'] : array();
		if ( true === ( $warehouse['skipped'] ?? false ) ) {
			return 'Подключение ПЭК работает. Выберите склад самопривоза для полной operational-проверки.';
		}

		return 'Подключение ПЭК частично работает. Некоторые API-проверки завершились ошибкой; подробности приведены ниже.';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
