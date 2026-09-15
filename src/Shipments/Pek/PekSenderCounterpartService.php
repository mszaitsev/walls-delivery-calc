<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekCounterpartContractException extends \RuntimeException {
	/** @param array<string,mixed> $diagnostic */
	public function __construct( string $reason, private array $diagnostic ) {
		parent::__construct( $reason );
	}

	/** @return array<string,mixed> */
	public function diagnostic(): array {
		return $this->diagnostic;
	}
}

final class PekSenderCounterpartService {
	private const ENDPOINT = '/counterparts/confirmedaccesstocounterparties/';

	public function __construct(
		private PekApiClient $api,
		private PekPrivateAccessTokenService $tokens,
		private PekSettings $settings,
		private PekCredentials $credentials
	) {
	}

	/** @return array<string,mixed> */
	public function verify_and_save(): array {
		try {
			$token = $this->tokens->token();
		} catch ( PekApiException $exception ) {
			return $this->failed_verification( 'Не удалось получить private token ПЭК для проверки контрагента.', $this->api_diagnostic( 'private_token', $exception, '/auth/createtokentoaccessprivatedata/' ) );
		} catch ( \Throwable ) {
			return $this->failed_verification( 'Не удалось получить private token ПЭК для проверки контрагента.', $this->diagnostic( array( 'stage' => 'private_token', 'endpoint' => '/auth/createtokentoaccessprivatedata/', 'method' => 'POST', 'error_code' => 'private_token_unexpected' ) ) );
		}

		try {
			$rows = $this->api->confirmed_counterparties( $token );
		} catch ( PekApiException $exception ) {
			$diagnostic = $this->api_diagnostic( 'counterpart_api', $exception, self::ENDPOINT );
			$stage = (string) $diagnostic['stage'];
			if ( 'counterpart_logical' === $stage ) {
				return $this->failed_verification( 'ПЭК отклонил запрос списка подтверждённых контрагентов.', $diagnostic );
			}
			if ( 'counterpart_contract' === $stage ) {
				return $this->failed_verification( 'ПЭК вернул некорректную структуру списка контрагентов.', $diagnostic );
			}

			return $this->failed_verification( 'Не удалось получить список подтверждённых контрагентов ПЭК.', $diagnostic );
		} catch ( \Throwable ) {
			return $this->failed_verification( 'Не удалось получить список подтверждённых контрагентов ПЭК.', $this->diagnostic( array( 'stage' => 'counterpart_api', 'endpoint' => self::ENDPOINT, 'method' => 'POST', 'error_code' => 'counterpart_api_unexpected' ) ) );
		}

		try {
			$matches = array();
			$counters = $this->empty_counters( count( $rows ) );
			$configured_card = trim( $this->settings->client_card() );
			foreach ( $rows as $index => $row ) {
				$legal_form = $this->row_legal_form( $row, (int) $index, $counters );
				if ( 3 === $legal_form ) {
					++$counters['physical_rows'];
					continue;
				}
				if ( 1 === $legal_form ) {
					++$counters['legal_entity_rows'];
				} else {
					++$counters['entrepreneur_rows'];
				}
				$row = $this->normalize_legal_row( $row, (int) $index, $counters );
				if (
					$row['legalForm'] === $this->settings->sender_legal_form()
					&& $row['inn'] === $this->settings->sender_inn()
					&& ( PekSettings::LEGAL_FORM_LEGAL_ENTITY !== $this->settings->sender_legal_form() || $row['kpp'] === $this->settings->sender_kpp() )
					&& ( '' === $configured_card || $row['counterpartClientCard'] === $configured_card )
				) {
					$matches[] = $row;
				}
			}
			$counters['matched_rows'] = count( $matches );
		} catch ( PekCounterpartContractException $exception ) {
			return $this->failed_verification( 'ПЭК вернул некорректную структуру списка контрагентов.', $exception->diagnostic() );
		}
		if ( 1 !== count( $matches ) ) {
			return $this->failed_verification(
				0 === count( $matches ) ? 'ПЭК не подтвердил контрагента отправителя по ИНН/КПП.' : 'ПЭК вернул несколько подходящих контрагентов отправителя; выбор заблокирован.',
				$this->diagnostic( array_merge( array( 'stage' => 'counterpart_match', 'endpoint' => self::ENDPOINT, 'method' => 'POST', 'reason' => 0 === count( $matches ) ? 'no_match' : 'multiple_matches' ), $counters ) )
			);
		}
		$row = $matches[0];
		$guid = $row['guid'];
		$snapshot = array(
			'guid' => $guid,
			'legalForm' => $row['legalForm'],
			'title' => $row['title'],
			'inn_masked' => $this->mask( $row['inn'] ),
			'kpp_masked' => $this->mask( $row['kpp'] ),
			'client_card_present' => '' !== $row['counterpartClientCard'],
			'identity_hash' => $this->settings->sender_identity_hash(),
			'account_login_hash' => $this->credentials->account_login_hash(),
			'checked_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		$this->settings->save_sender_counterpart( $guid, $snapshot );

		return array(
			'success' => true,
			'message' => 'Контрагент отправителя ПЭК подтверждён.',
			'snapshot' => $snapshot,
			'diagnostic' => $this->diagnostic( array_merge( array( 'stage' => 'success', 'endpoint' => self::ENDPOINT, 'method' => 'POST' ), $counters ) ),
		);
	}

	/** @param array<string,mixed> $diagnostic @return array{success:false,message:string,diagnostic:array<string,mixed>} */
	private function failed_verification( string $message, array $diagnostic = array() ): array {
		$this->settings->save_sender_counterpart( '', array() );

		return array( 'success' => false, 'message' => $message, 'diagnostic' => $this->diagnostic( $diagnostic ) );
	}

	/** @param array<string,int> $counters */
	private function row_legal_form( mixed $row, int $index, array $counters ): int {
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			$this->contract_failure( 'row_not_object', $index, null, $counters );
		}
		$value = $row['legalForm'] ?? null;
		if ( ! is_int( $value ) || is_bool( $value ) ) {
			$this->contract_failure( 'legal_form_type', $index, null, $counters );
		}
		if ( ! in_array( $value, array( 1, 2, 3 ), true ) ) {
			$this->contract_failure( 'unsupported_legal_form', $index, $value, $counters );
		}

		return $value;
	}

	/** @param array<string,mixed> $row @param array<string,int> $counters @return array{legalForm:int,title:string,guid:string,counterpartClientCard:string,inn:string,kpp:string} */
	private function normalize_legal_row( array $row, int $index, array $counters ): array {
		$legal_form = $row['legalForm'];
		if ( ! in_array( $legal_form, array( 1, 2 ), true ) ) {
			$this->contract_failure( 'legal_form_relevance', $index, is_int( $legal_form ) ? $legal_form : null, $counters );
		}
		if ( ! is_string( $row['title'] ?? null ) || '' === trim( $row['title'] ) ) {
			$this->contract_failure( 'legal_title_type', $index, $legal_form, $counters );
		}
		if ( ! is_string( $row['guid'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim( $row['guid'] ) ) ) {
			$this->contract_failure( 'legal_guid', $index, $legal_form, $counters );
		}
		$card = $row['counterpartClientCard'] ?? null;
		if ( null !== $card && ! is_string( $card ) ) {
			$this->contract_failure( 'legal_card_type', $index, $legal_form, $counters );
		}
		$legal = $row['legal'] ?? null;
		if ( ! is_array( $legal ) || array_is_list( $legal ) ) {
			$this->contract_failure( 'legal_object', $index, $legal_form, $counters );
		}
		if ( ! is_string( $legal['inn'] ?? null ) ) {
			$this->contract_failure( 'legal_inn_type', $index, $legal_form, $counters );
		}
		$inn = trim( $legal['inn'] );
		if ( 1 !== preg_match( '/^\d+$/', $inn ) || ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $legal_form && 10 !== strlen( $inn ) ) || ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $legal_form && 12 !== strlen( $inn ) ) ) {
			$this->contract_failure( 'legal_inn_value', $index, $legal_form, $counters );
		}
		$kpp = $legal['kpp'] ?? null;
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $legal_form && ! is_string( $kpp ) ) {
			$this->contract_failure( 'legal_kpp_type', $index, $legal_form, $counters );
		}
		if ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $legal_form && null !== $kpp && ! is_string( $kpp ) ) {
			$this->contract_failure( 'legal_kpp_type', $index, $legal_form, $counters );
		}
		$kpp = is_string( $kpp ) ? trim( $kpp ) : '';
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $legal_form && 1 !== preg_match( '/^\d{9}$/', $kpp ) ) {
			$this->contract_failure( 'legal_kpp_value', $index, $legal_form, $counters );
		}
		if ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $legal_form && '' !== $kpp && 1 !== preg_match( '/^\d{1,12}$/', $kpp ) ) {
			$this->contract_failure( 'legal_kpp_value', $index, $legal_form, $counters );
		}

		return array(
			'legalForm' => $legal_form,
			'title' => trim( $row['title'] ),
			'guid' => trim( $row['guid'] ),
			'counterpartClientCard' => null === $card ? '' : trim( $card ),
			'inn' => $inn,
			'kpp' => $kpp,
		);
	}

	/** @param array<string,int> $counters */
	private function contract_failure( string $reason, int $index, ?int $legal_form, array $counters ): never {
		throw new PekCounterpartContractException(
			$reason,
			$this->diagnostic(
				array_merge(
					array(
						'stage' => 'counterpart_contract',
						'endpoint' => self::ENDPOINT,
						'method' => 'POST',
						'error_code' => 'counterpart_contract',
						'reason' => $reason,
						'row_index' => $index,
						'legalForm' => $legal_form,
					),
					$counters
				)
			)
		);
	}

	/** @return array<string,int> */
	private function empty_counters( int $total_rows = 0 ): array {
		return array(
			'total_rows' => max( 0, $total_rows ),
			'legal_entity_rows' => 0,
			'entrepreneur_rows' => 0,
			'physical_rows' => 0,
			'matched_rows' => 0,
		);
	}

	/** @return array<string,mixed> */
	private function api_diagnostic( string $default_stage, PekApiException $exception, string $endpoint ): array {
		$context = $exception->context();
		$failure_stage = (string) ( $context['failure_stage'] ?? '' );
		$error_code = (string) ( $context['error_code'] ?? '' );
		$stage = $default_stage;
		if ( 'private_token' === $default_stage ) {
			$stage = 'private_token';
		} elseif ( str_contains( $failure_stage, 'logical' ) || in_array( $error_code, array( 'pek_logical_error', 'pek_has_error' ), true ) ) {
			$stage = 'counterpart_logical';
		} elseif ( str_contains( $failure_stage, 'contract' ) ) {
			$stage = 'counterpart_contract';
		}

		return $this->diagnostic(
			array(
				'stage' => $stage,
				'endpoint' => is_string( $context['endpoint'] ?? null ) && '' !== trim( $context['endpoint'] ) ? (string) $context['endpoint'] : $endpoint,
				'method' => is_string( $context['method'] ?? null ) && '' !== trim( $context['method'] ) ? (string) $context['method'] : 'POST',
				'http_status' => $context['http_status'] ?? '',
				'error_code' => '' !== $error_code ? $error_code : 'pek_api_error',
				'reason' => '' !== $failure_stage ? $failure_stage : $error_code,
			)
		);
	}

	/** @param array<string,mixed> $values @return array<string,mixed> */
	private function diagnostic( array $values ): array {
		$counters = $this->empty_counters( (int) ( $values['total_rows'] ?? 0 ) );
		foreach ( $counters as $key => $default ) {
			$counters[ $key ] = max( 0, (int) ( $values[ $key ] ?? $default ) );
		}
		$status = $values['http_status'] ?? '';
		if ( ! is_int( $status ) && ! is_string( $status ) ) {
			$status = '';
		}
		$legal_form = $values['legalForm'] ?? null;

		return array_merge(
			array(
				'stage' => $this->safe_token( (string) ( $values['stage'] ?? '' ) ),
				'endpoint' => $this->safe_endpoint( (string) ( $values['endpoint'] ?? '' ) ),
				'method' => $this->safe_method( (string) ( $values['method'] ?? '' ) ),
				'http_status' => $status,
				'error_code' => $this->safe_token( (string) ( $values['error_code'] ?? '' ) ),
				'reason' => $this->safe_token( (string) ( $values['reason'] ?? '' ) ),
				'row_index' => array_key_exists( 'row_index', $values ) ? ( is_int( $values['row_index'] ) ? $values['row_index'] : null ) : null,
				'legalForm' => is_int( $legal_form ) ? $legal_form : null,
			),
			$counters
		);
	}

	private function safe_token( string $value ): string {
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[a-z0-9_:-]{1,80}$/', $value ) ? $value : '';
	}

	private function safe_endpoint( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '#^/[a-z0-9/_-]+/$#i', $value ) ? $value : '';
	}

	private function safe_method( string $value ): string {
		$value = strtoupper( trim( $value ) );
		return in_array( $value, array( 'GET', 'POST' ), true ) ? $value : '';
	}

	private function digits( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}

	private function mask( string $value ): string {
		$digits = $this->digits( $value );
		if ( '' === $digits ) {
			return '';
		}

		return str_repeat( '*', max( 0, strlen( $digits ) - 2 ) ) . substr( $digits, -2 );
	}
}
