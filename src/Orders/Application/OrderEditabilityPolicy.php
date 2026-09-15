<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OrderEditabilityPolicy {
	public function __construct( private SettingsRepository $settings ) {
	}

	public function register(): void {
		add_filter( 'wc_order_is_editable', array( $this, 'filter' ), 10, 2 );
	}

	public function filter( bool $editable, mixed $order = null ): bool {
		unset( $order );

		return $this->settings->get_bool( SettingsRepository::ALLOW_EDIT_ORDERS_IN_ALL_STATUSES_KEY, false ) ? true : $editable;
	}
}
