<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationAjax {
	public const ACTION = 'wdc_platform_search_locations';
	public const NONCE_ACTION = 'wdc_platform_location_search';

	public function __construct(
		private CheckoutLocationSearch $search,
		private SettingsRepository $settings
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( function_exists( 'wp_verify_nonce' ) && ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->send_error( __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ), 403 );
			return;
		}

		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['query'] ) ) : '';
		$this->send_success( $this->payload( $query ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function payload( string $query ): array {
		$limit = $this->limit();
		if ( $this->length( $query ) < 3 ) {
			return array( 'groups' => array(), 'limit' => $limit, 'limit_reached' => false );
		}

		$groups = array();
		$total  = 0;
		foreach ( $this->search->grouped( $query, $limit ) as $region => $locations ) {
			$total += count( $locations );
			$groups[] = array(
				'region'    => (string) $region,
				'locations' => array_map( array( $this, 'location_payload' ), $locations ),
			);
		}

		return array_merge(
			array( 'groups' => $groups, 'limit' => $limit, 'limit_reached' => $total >= $limit ),
			$this->search->last_search_meta()
		);
	}

	private function limit(): int {
		return max( 10, min( 300, $this->settings->get_int( 'location_search_limit', 100 ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_payload( Location $location ): array {
		return array(
			'id'              => $location->id,
			'city_name'       => $location->city_name,
			'settlement_name' => $location->settlement_name,
			'display_name'    => $location->display_name,
			'postcode'        => $location->postcode,
			'region_name'     => $location->region_name,
			'region_code'     => $location->region_code,
			'fias_id'         => $location->fias_id,
			'gar_id'          => $location->gar_id,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function send_success( array $data ): void {
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( $data );
			return;
		}

		echo wp_json_encode( array( 'success' => true, 'data' => $data ) );
	}

	private function send_error( string $message, int $status_code ): void {
		if ( function_exists( 'wp_send_json_error' ) ) {
			wp_send_json_error( array( 'message' => $message ), $status_code );
			return;
		}

		echo wp_json_encode( array( 'success' => false, 'data' => array( 'message' => $message ) ) );
	}

	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( trim( $value ), 'UTF-8' ) : strlen( trim( $value ) );
	}
}
