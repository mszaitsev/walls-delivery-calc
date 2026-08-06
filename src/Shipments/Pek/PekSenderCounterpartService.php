<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekSenderCounterpartService {
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
			$rows = $this->api->confirmed_counterparties( $this->tokens->token() );
			$matches = array();
			$configured_card = trim( $this->settings->client_card() );
			foreach ( $rows as $row ) {
				$row = $this->normalize_row( $row );
				if (
					$row['legalForm'] === $this->settings->sender_legal_form()
					&& $row['inn'] === $this->settings->sender_inn()
					&& ( PekSettings::LEGAL_FORM_LEGAL_ENTITY !== $this->settings->sender_legal_form() || $row['kpp'] === $this->settings->sender_kpp() )
					&& ( '' === $configured_card || $row['counterpartClientCard'] === $configured_card )
				) {
					$matches[] = $row;
				}
			}
		} catch ( \Throwable ) {
			return $this->failed_verification( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( 1 !== count( $matches ) ) {
			return $this->failed_verification( 0 === count( $matches ) ? 'ПЭК не подтвердил контрагента отправителя по ИНН/КПП.' : 'ПЭК вернул несколько контрагентов отправителя; выбор заблокирован.' );
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

		return array( 'success' => true, 'message' => 'Контрагент отправителя ПЭК подтверждён.', 'snapshot' => $snapshot );
	}

	/** @return array{success:false,message:string} */
	private function failed_verification( string $message ): array {
		$this->settings->save_sender_counterpart( '', array() );

		return array( 'success' => false, 'message' => $message );
	}

	/** @param mixed $row @return array{legalForm:int,title:string,guid:string,counterpartClientCard:string,inn:string,kpp:string} */
	private function normalize_row( mixed $row ): array {
		if ( ! is_array( $row ) || array_is_list( $row ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( ! is_int( $row['legalForm'] ?? null ) || ! in_array( $row['legalForm'], array( 1, 2 ), true ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( ! is_string( $row['title'] ?? null ) || '' === trim( $row['title'] ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( ! is_string( $row['guid'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim( $row['guid'] ) ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный GUID контрагента отправителя.' );
		}
		$card = $row['counterpartClientCard'] ?? null;
		if ( null !== $card && ! is_string( $card ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		$legal = $row['legal'] ?? null;
		if ( ! is_array( $legal ) || array_is_list( $legal ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( ! is_string( $legal['inn'] ?? null ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		$inn = trim( $legal['inn'] );
		if ( 1 !== preg_match( '/^\d+$/', $inn ) || ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $row['legalForm'] && 10 !== strlen( $inn ) ) || ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $row['legalForm'] && 12 !== strlen( $inn ) ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		$kpp = $legal['kpp'] ?? null;
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $row['legalForm'] && ! is_string( $kpp ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $row['legalForm'] && null !== $kpp && ! is_string( $kpp ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		$kpp = is_string( $kpp ) ? trim( $kpp ) : '';
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $row['legalForm'] && 1 !== preg_match( '/^\d{9}$/', $kpp ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}
		if ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $row['legalForm'] && '' !== $kpp && 1 !== preg_match( '/^\d{1,12}$/', $kpp ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректные данные контрагента отправителя.' );
		}

		return array(
			'legalForm' => $row['legalForm'],
			'title' => trim( $row['title'] ),
			'guid' => trim( $row['guid'] ),
			'counterpartClientCard' => null === $card ? '' : trim( $card ),
			'inn' => $inn,
			'kpp' => $kpp,
		);
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
