<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekSenderCounterpartService {
	public function __construct(
		private PekApiClient $api,
		private PekPrivateAccessTokenService $tokens,
		private PekSettings $settings
	) {
	}

	/** @return array<string,mixed> */
	public function verify_and_save(): array {
		$rows = $this->api->confirmed_counterparties( $this->tokens->token() );
		$matches = array();
		foreach ( $rows as $row ) {
			$legal = is_array( $row['legal'] ?? null ) ? $row['legal'] : array();
			if (
				(int) ( $row['legalForm'] ?? 0 ) === $this->settings->sender_legal_form()
				&& $this->digits( (string) ( $legal['inn'] ?? '' ) ) === $this->settings->sender_inn()
				&& ( '' === $this->settings->sender_kpp() || $this->digits( (string) ( $legal['kpp'] ?? '' ) ) === $this->settings->sender_kpp() )
			) {
				$matches[] = $row;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return array( 'success' => false, 'message' => 0 === count( $matches ) ? 'ПЭК не подтвердил контрагента отправителя по ИНН/КПП.' : 'ПЭК вернул несколько контрагентов отправителя; выбор заблокирован.' );
		}
		$row = $matches[0];
		$guid = trim( (string) ( $row['guid'] ?? '' ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid ) ) {
			return array( 'success' => false, 'message' => 'ПЭК вернул некорректный GUID контрагента отправителя.' );
		}
		$legal = is_array( $row['legal'] ?? null ) ? $row['legal'] : array();
		$snapshot = array(
			'guid' => $guid,
			'legalForm' => (int) ( $row['legalForm'] ?? 0 ),
			'title' => (string) ( $row['title'] ?? '' ),
			'inn_masked' => $this->mask( (string) ( $legal['inn'] ?? '' ) ),
			'kpp_masked' => $this->mask( (string) ( $legal['kpp'] ?? '' ) ),
			'client_card_present' => '' !== trim( (string) ( $row['counterpartClientCard'] ?? '' ) ),
			'checked_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
		$this->settings->save_sender_counterpart( $guid, $snapshot );

		return array( 'success' => true, 'message' => 'Контрагент отправителя ПЭК подтверждён.', 'snapshot' => $snapshot );
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
