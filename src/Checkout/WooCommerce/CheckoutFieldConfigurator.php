<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutFieldConfigurator {
	public const ADDRESS_LABEL = 'Адрес - нужен при доставке посылки курьером домой. В других случаях не обязательно';
	public const ADDRESS_PLACEHOLDER = 'Улица, дом, корпус, квартира';
	public const PHONE_PLACEHOLDER = '+7-ххх-ххх-хххх (или формат вашей страны)';
	public const ORDER_COMMENTS_PLACEHOLDER = "Пишем, если заказ в подарок;\nотправить заказы вместе;\nзаберёт другой человек и т.д.";

	public function register(): void {
		add_filter( 'woocommerce_default_address_fields', array( $this, 'configure_default_address_fields' ), 20, 1 );
		add_filter( 'woocommerce_billing_fields', array( $this, 'configure_billing_fields' ), 20, 1 );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'configure_checkout_fields' ), 100, 1 );
		add_filter( 'woocommerce_form_field_args', array( $this, 'configure_form_field_args' ), 100, 2 );
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public function configure_default_address_fields( array $fields ): array {
		$this->set_priority( $fields, 'country', 30 );
		$this->set_priority( $fields, 'city', 40 );
		$this->set_priority( $fields, 'state', 50 );
		$this->set_priority( $fields, 'postcode', 60 );
		$this->set_priority( $fields, 'address_1', 70 );

		if ( isset( $fields['address_1'] ) ) {
			$fields['address_1']['label'] = __( self::ADDRESS_LABEL, 'walls-delivery-calc' );
			$fields['address_1']['placeholder'] = __( self::ADDRESS_PLACEHOLDER, 'walls-delivery-calc' );
			$fields['address_1']['required'] = false;
		}

		return $fields;
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public function configure_billing_fields( array $fields ): array {
		unset( $fields['billing_address_2'] );

		$this->set_priority( $fields, 'billing_first_name', 10 );
		$this->set_priority( $fields, 'billing_last_name', 20 );
		$this->set_priority( $fields, 'billing_country', 30 );
		$this->set_priority( $fields, 'billing_city', 40 );
		$this->set_priority( $fields, 'billing_state', 50 );
		$this->set_priority( $fields, 'billing_postcode', 60 );
		$this->set_priority( $fields, 'billing_address_1', 70 );
		$this->set_priority( $fields, 'billing_phone', 80 );
		$this->set_priority( $fields, 'billing_email', 90 );

		if ( isset( $fields['billing_first_name'] ) ) {
			$fields['billing_first_name']['label'] = __( 'Имя и отчество', 'walls-delivery-calc' );
		}
		if ( isset( $fields['billing_address_1'] ) ) {
			$fields['billing_address_1']['label'] = __( self::ADDRESS_LABEL, 'walls-delivery-calc' );
			$fields['billing_address_1']['placeholder'] = __( self::ADDRESS_PLACEHOLDER, 'walls-delivery-calc' );
			$fields['billing_address_1']['required'] = false;
		}
		if ( isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required'] = true;
			$fields['billing_phone']['placeholder'] = __( self::PHONE_PLACEHOLDER, 'walls-delivery-calc' );
		}

		return $fields;
	}

	/**
	 * @param array<string,array<string,array<string,mixed>>> $fields
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function configure_checkout_fields( array $fields ): array {
		$fields['billing'] = $this->configure_billing_fields( $fields['billing'] ?? array() );

		if ( isset( $fields['order']['order_comments'] ) ) {
			$fields['order']['order_comments']['placeholder'] = self::ORDER_COMMENTS_PLACEHOLDER;
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function configure_form_field_args( array $args, string $key ): array {
		if ( 'billing_address_1' === $key ) {
			$args['label'] = __( self::ADDRESS_LABEL, 'walls-delivery-calc' );
			$args['placeholder'] = __( self::ADDRESS_PLACEHOLDER, 'walls-delivery-calc' );
			$args['required'] = false;
		}
		if ( 'billing_phone' === $key ) {
			$args['required'] = true;
			$args['placeholder'] = __( self::PHONE_PLACEHOLDER, 'walls-delivery-calc' );
		}

		return $args;
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
