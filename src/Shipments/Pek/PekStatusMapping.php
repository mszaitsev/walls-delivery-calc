<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class PekStatusMapping {
	public const MAPPING_KEY = 'pek_status_mapping';
	public const ISSUED_PLACES_PATTERN_KEY = '__issued_places_partial_pattern__';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	/**
	 * @return array<string,array{label:string,pattern:bool}>
	 */
	public static function statuses(): array {
		return array(
			'аннулировано до приемки груза' => self::status( 'Аннулировано до приемки груза' ),
			'заявка на забор зарегистрирована' => self::status( 'Заявка на забор зарегистрирована' ),
			'ожидается передача груза от отправителя' => self::status( 'Ожидается передача груза от отправителя' ),
			'оформлен' => self::status( 'Оформлен' ),
			'принят к перевозке' => self::status( 'Принят к перевозке' ),
			'принят на пвз' => self::status( 'Принят на ПВЗ' ),
			'в пути' => self::status( 'В пути' ),
			'в пути на терминал' => self::status( 'В пути на терминал' ),
			'прибыл частично' => self::status( 'Прибыл частично' ),
			'разгружается ожидайте оповещения' => self::status( 'Разгружается. Ожидайте оповещения' ),
			'прибыл' => self::status( 'Прибыл' ),
			'выполняется адресная доставка' => self::status( 'Выполняется адресная доставка' ),
			'выдан получателю' => self::status( 'Выдан получателю' ),
			'доставлен получателю' => self::status( 'Доставлен получателю' ),
			self::ISSUED_PLACES_PATTERN_KEY => self::status( 'Выдан мест N из M', true ),
			'отправлен на возврат' => self::status( 'Отправлен на возврат' ),
			'возврат груза отправителю' => self::status( 'Возврат груза отправителю' ),
			'возвращен отправителю' => self::status( 'Возвращен отправителю' ),
			'утилизирован' => self::status( 'Утилизирован' ),
			'изъят на таможне' => self::status( 'Изъят на таможне' ),
		);
	}

	/**
	 * @return array<string,array{pickup:string,courier:string}>
	 */
	public static function default_mapping(): array {
		return array(
			'аннулировано до приемки груза' => self::pair( DeliveryStatus::CANCELLED ),
			'заявка на забор зарегистрирована' => self::pair( DeliveryStatus::CREATED_IN_CARRIER ),
			'ожидается передача груза от отправителя' => self::pair( DeliveryStatus::CREATED_IN_CARRIER ),
			'оформлен' => self::pair( DeliveryStatus::CREATED_IN_CARRIER ),
			'принят к перевозке' => self::pair( DeliveryStatus::CREATED_IN_CARRIER ),
			'принят на пвз' => self::pair( DeliveryStatus::CREATED_IN_CARRIER ),
			'в пути' => self::pair( DeliveryStatus::IN_TRANSIT ),
			'в пути на терминал' => self::pair( DeliveryStatus::IN_TRANSIT ),
			'прибыл частично' => self::pair( DeliveryStatus::IN_TRANSIT ),
			'разгружается ожидайте оповещения' => self::pair( DeliveryStatus::IN_TRANSIT ),
			'прибыл' => array(
				'pickup' => DeliveryStatus::READY_FOR_PICKUP,
				'courier' => DeliveryStatus::IN_TRANSIT,
			),
			'выполняется адресная доставка' => self::pair( DeliveryStatus::HANDED_TO_COURIER ),
			'выдан получателю' => self::pair( DeliveryStatus::DELIVERED ),
			'доставлен получателю' => self::pair( DeliveryStatus::DELIVERED ),
			self::ISSUED_PLACES_PATTERN_KEY => self::pair( DeliveryStatus::DELIVERED ),
			'отправлен на возврат' => self::pair( DeliveryStatus::RETURNING_TO_SENDER ),
			'возврат груза отправителю' => self::pair( DeliveryStatus::RETURNING_TO_SENDER ),
			'возвращен отправителю' => self::pair( DeliveryStatus::RETURNED_TO_SENDER ),
			'утилизирован' => self::pair( DeliveryStatus::REJECTED ),
			'изъят на таможне' => self::pair( DeliveryStatus::REJECTED ),
		);
	}

	/**
	 * @return array<string,array{pickup:string,courier:string}>
	 */
	public function mapping(): array {
		$stored = $this->settings->get_array( self::MAPPING_KEY, array() );

		return $this->sanitize_mapping( array_replace_recursive( self::default_mapping(), $stored ) );
	}

	/**
	 * @param array<string,mixed> $mapping
	 */
	public function save_mapping( array $mapping ): void {
		$this->settings->set( self::MAPPING_KEY, $this->sanitize_mapping( $mapping ) );
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array<string,array{pickup:string,courier:string}>
	 */
	public function sanitize_mapping( array $mapping ): array {
		$result = array();
		$defaults = self::default_mapping();
		foreach ( array_keys( self::statuses() ) as $key ) {
			$row = is_array( $mapping[ $key ] ?? null ) ? $mapping[ $key ] : array();
			$result[ $key ] = array(
				'pickup' => $this->sanitize_status_value( $row['pickup'] ?? null, $defaults[ $key ]['pickup'] ?? DeliveryStatus::UNKNOWN ),
				'courier' => $this->sanitize_status_value( $row['courier'] ?? null, $defaults[ $key ]['courier'] ?? DeliveryStatus::UNKNOWN ),
			);
		}

		return $result;
	}

	public function is_pre_acceptance_status( string $status ): bool {
		return in_array(
			self::normalize_status( $status ),
			array( 'заявка на забор зарегистрирована', 'ожидается передача груза от отправителя', 'оформлен' ),
			true
		);
	}

	public function is_cancelled_status( string $status ): bool {
		return 'аннулировано до приемки груза' === self::normalize_status( $status );
	}

	public function is_accepted_status( string $status ): bool {
		$key = $this->mapping_key_for_status( $status );

		return '' !== $key
			&& ! $this->is_pre_acceptance_status( $status )
			&& ! $this->is_cancelled_status( $status );
	}

	public function is_terminal_status( string $status ): bool {
		return in_array(
			$this->mapping_key_for_status( $status ),
			array(
				'аннулировано до приемки груза',
				'выдан получателю',
				'доставлен получателю',
				self::ISSUED_PLACES_PATTERN_KEY,
				'возвращен отправителю',
				'утилизирован',
				'изъят на таможне',
			),
			true
		);
	}

	public function map( string $status, string $delivery_type = DeliveryType::PICKUP ): string {
		$key = $this->mapping_key_for_status( $status );
		if ( '' === $key ) {
			return DeliveryStatus::UNKNOWN;
		}

		$type = DeliveryType::COURIER === $delivery_type ? 'courier' : 'pickup';

		return (string) ( $this->mapping()[ $key ][ $type ] ?? DeliveryStatus::UNKNOWN );
	}

	public static function normalize_status( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : self::lower_utf8_without_mbstring( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function mapping_key_for_status( string $status ): string {
		$normalized = self::normalize_status( $status );
		if ( array_key_exists( $normalized, self::statuses() ) ) {
			return $normalized;
		}
		if ( 1 === preg_match( '/^выдан мест \d+ из \d+$/', $normalized ) ) {
			return self::ISSUED_PLACES_PATTERN_KEY;
		}

		return '';
	}

	private function sanitize_status_value( mixed $value, string $default ): string {
		$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' );

		return DeliveryStatus::is_valid( $value ) ? $value : $default;
	}

	/**
	 * @return array{label:string,pattern:bool}
	 */
	private static function status( string $label, bool $pattern = false ): array {
		return array(
			'label' => $label,
			'pattern' => $pattern,
		);
	}

	/**
	 * @return array{pickup:string,courier:string}
	 */
	private static function pair( string $status ): array {
		return array(
			'pickup' => $status,
			'courier' => $status,
		);
	}

	private static function lower_utf8_without_mbstring( string $value ): string {
		return strtr(
			strtolower( $value ),
			array(
				'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ж' => 'ж', 'З' => 'з',
				'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п',
				'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч',
				'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
			)
		);
	}
}
