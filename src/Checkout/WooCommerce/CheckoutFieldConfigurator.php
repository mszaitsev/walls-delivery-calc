<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutFieldConfigurator {
	public const ADDRESS_LABEL = 'Адрес - нужен при доставке посылки курьером домой. В других случаях не обязательно';
	public const ORDER_COMMENTS_PLACEHOLDER = "Здесь пишем:\n\n- если нужно несколько заказов отправить вместе\n- если готовый набор или видео-урок в подарок другому человеку\n- если хотите прислать курьера забрать заказ и т.д.";

	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'configure' ), 20, 1 );
	}

	/**
	 * @param array<string,array<string,array<string,mixed>>> $fields
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function configure( array $fields ): array {
		$fields['billing'] = $this->configure_address_fields( $fields['billing'] ?? array(), true );
		$fields['shipping'] = $this->configure_address_fields( $fields['shipping'] ?? array(), false );

		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['placeholder'] = self::ORDER_COMMENTS_PLACEHOLDER;
		}

		return $fields;
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	private function configure_address_fields( array $fields, bool $billing ): array {
		unset( $fields[ $billing ? 'billing_address_2' : 'shipping_address_2' ] );

		$prefix = $billing ? 'billing' : 'shipping';
		$this->set_priority( $fields, "{$prefix}_country", 30 );
		$this->set_priority( $fields, "{$prefix}_city", 40 );
		$this->set_priority( $fields, "{$prefix}_state", 50 );
		$this->set_priority( $fields, "{$prefix}_postcode", 60 );
		$this->set_priority( $fields, "{$prefix}_address_1", 70 );

		if ( isset( $fields["{$prefix}_address_1"] ) ) {
			$fields["{$prefix}_address_1"]['label'] = __( self::ADDRESS_LABEL, 'walls-delivery-calc' );
			$fields["{$prefix}_address_1"]['required'] = false;
		}

		if ( $billing ) {
			$this->set_priority( $fields, 'billing_first_name', 10 );
			$this->set_priority( $fields, 'billing_last_name', 20 );
			$this->set_priority( $fields, 'billing_phone', 80 );
			$this->set_priority( $fields, 'billing_email', 90 );

			if ( isset( $fields['billing_first_name'] ) ) {
				$fields['billing_first_name']['label'] = __( 'Имя и отчество', 'walls-delivery-calc' );
			}
			if ( isset( $fields['billing_phone'] ) ) {
				$fields['billing_phone']['required'] = true;
			}
		}

		return $fields;
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 */
	private function set_priority( array &$fields, string $key, int $priority ): void {
		if ( isset( $fields[ $key ] ) ) {
			$fields[ $key ]['priority'] = $priority;
		}
	}
}
