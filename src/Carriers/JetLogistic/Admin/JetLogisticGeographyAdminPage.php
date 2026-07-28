<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyAdminPage {
	private const NONCE_ACTION = 'wdc_jet_logistic_geography';

	public function __construct(
		private JetLogisticGeographyImportService $imports,
		private JetLogisticGeographyOverrideRepository $overrides,
		private LocationRepository $locations,
		private JetLogisticSettings $settings,
		private JetLogisticCredentials $credentials
	) {
	}

	public function register(): void {
		add_submenu_page( AdminMenu::SLUG, 'География Джет', 'География Джет', AdminMenu::CAPABILITY, 'wdc-jet-logistic-geography', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'walls-delivery-calc' ) );
		}
		$message = '';
		if ( 'POST' === (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( self::NONCE_ACTION ) ) {
			$action = sanitize_key( wp_unslash( $_POST['jet_action'] ?? '' ) );
			if ( 'import_csv' === $action && isset( $_FILES['cities_csv']['tmp_name'] ) ) {
				$csv = (string) file_get_contents( (string) $_FILES['cities_csv']['tmp_name'] );
				$result = $this->imports->import_csv( $csv );
				$message = ! empty( $result['success'] ) ? 'Импорт Jet завершен.' : (string) ( $result['message'] ?? 'Импорт Jet не выполнен.' );
			}
			if ( 'override' === $action ) {
				$identity = sanitize_text_field( wp_unslash( (string) ( $_POST['source_identity'] ?? '' ) ) );
				$location_id = max( 0, (int) ( $_POST['location_id'] ?? 0 ) );
				$location = $this->locations->find_by_id( $location_id );
				if ( '' !== $identity && null !== $location && $location->active ) {
					$this->overrides->save( $identity, $location_id, $location->country_code );
					$message = 'Ручное сопоставление Jet сохранено.';
				}
			}
			$this->settings->save_from_admin( $_POST );
			$token = trim( (string) ( $_POST['jet_logistic_access_token'] ?? '' ) );
			if ( '' !== $token ) {
				$this->credentials->save_access_token( sanitize_text_field( wp_unslash( $token ) ) );
			}
		}
		echo '<div class="wrap"><h1>География Джет</h1>';
		if ( '' !== $message ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
		}
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="jet_action" value="import_csv" />';
		echo '<p><input type="file" name="cities_csv" accept=".csv,text/csv" /> <button class="button button-primary">Загрузить cities.csv</button></p>';
		echo '<p><label>HTTP timeout <input type="number" min="1" max="60" name="' . esc_attr( JetLogisticSettings::REQUEST_TIMEOUT_KEY ) . '" value="' . esc_attr( (string) $this->settings->request_timeout() ) . '" /></label></p>';
		echo '<p><label>Origin source identity <input class="regular-text" name="' . esc_attr( JetLogisticSettings::ORIGIN_SOURCE_IDENTITY_KEY ) . '" value="' . esc_attr( $this->settings->origin_source_identity() ) . '" /></label></p>';
		echo '<p><label>Jet API access token <input type="password" class="regular-text" name="jet_logistic_access_token" value="" autocomplete="new-password" /></label></p>';
		echo '</form></div>';
	}
}
