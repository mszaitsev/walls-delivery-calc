<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class PekStatusMapping {
	public function map( string $status, string $delivery_type = DeliveryType::PICKUP ): string {
		$normalized = $this->normalize( $status );
		if ( 'аннулировано до приемки груза' === $normalized ) {
			return DeliveryStatus::CANCELLED;
		}
		if ( in_array( $normalized, array( 'заявка на забор зарегистрирована', 'ожидается передача груза от отправителя', 'оформлен', 'принят к перевозке', 'принят на пвз' ), true ) ) {
			return DeliveryStatus::CREATED_IN_CARRIER;
		}
		if ( in_array( $normalized, array( 'в пути', 'в пути на терминал', 'прибыл частично', 'разгружается ожидайте оповещения' ), true ) ) {
			return DeliveryStatus::IN_TRANSIT;
		}
		if ( 'прибыл' === $normalized ) {
			return DeliveryType::PICKUP === $delivery_type ? DeliveryStatus::READY_FOR_PICKUP : DeliveryStatus::IN_TRANSIT;
		}
		if ( 'выполняется адресная доставка' === $normalized ) {
			return DeliveryStatus::HANDED_TO_COURIER;
		}
		if ( in_array( $normalized, array( 'выдан получателю', 'доставлен получателю' ), true ) || 1 === preg_match( '/^выдан мест \d+ из \d+$/', $normalized ) ) {
			return DeliveryStatus::DELIVERED;
		}
		if ( in_array( $normalized, array( 'отправлен на возврат', 'возврат груза отправителю' ), true ) ) {
			return DeliveryStatus::RETURNING_TO_SENDER;
		}
		if ( 'возвращен отправителю' === $normalized ) {
			return DeliveryStatus::RETURNED_TO_SENDER;
		}
		if ( in_array( $normalized, array( 'утилизирован', 'изъят на таможне' ), true ) ) {
			return DeliveryStatus::REJECTED;
		}

		return DeliveryStatus::UNKNOWN;
	}

	private function normalize( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : $this->lower_utf8_without_mbstring( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function lower_utf8_without_mbstring( string $value ): string {
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
