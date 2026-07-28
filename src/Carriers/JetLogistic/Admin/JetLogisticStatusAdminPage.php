<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusAdminPage {
	private const NONCE_ACTION = 'wdc_jet_logistic_statuses';

	public function __construct( private JetLogisticStatusMappingRepository $repository ) {
	}

	public function register(): void {
		add_submenu_page( AdminMenu::SLUG, 'Статусы Джет', 'Статусы Джет', AdminMenu::CAPABILITY, 'wdc-jet-logistic-statuses', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'walls-delivery-calc' ) );
		}
		$message = '';
		if ( 'POST' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( self::NONCE_ACTION ) ) {
			$external = sanitize_text_field( wp_unslash( (string) ( $_POST['external_status'] ?? '' ) ) );
			$universal = sanitize_key( wp_unslash( (string) ( $_POST['universal_status'] ?? '' ) ) );
			$active = ! empty( $_POST['active'] );
			if ( '' !== $external && ( '' === $universal || DeliveryStatus::is_valid( $universal ) ) ) {
				$this->repository->save_mapping( $external, $universal, $active );
				$message = 'Сопоставление статуса Jet сохранено.';
			}
		}
		echo '<div class="wrap"><h1>Статусы Джет</h1>';
		if ( '' !== $message ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<p><label>Внешний статус <input class="regular-text" name="external_status" /></label></p>';
		echo '<p><label>Универсальный статус <select name="universal_status"><option value="">Не сопоставлять</option>';
		foreach ( DeliveryStatus::labels() as $status => $label ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( $label . ' (' . $status . ')' ) . '</option>';
		}
		echo '</select></label></p><p><label><input type="checkbox" name="active" value="1" checked /> Активно</label></p><p><button class="button button-primary">Сохранить</button></p></form></div>';
	}
}
