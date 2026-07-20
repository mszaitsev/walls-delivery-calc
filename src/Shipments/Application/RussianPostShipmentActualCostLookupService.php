<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentActualCostLookupService {
	public function __construct(
		private RussianPostOtpravkaApiClient $otpravka_client,
		private RussianPostShipmentActualCostExtractor $extractor
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function lookup_after_create( string $barcode ): array {
		$barcode = trim( $barcode );
		if ( '' === $barcode ) {
			return array( 'cost' => null, 'error_code' => 'empty_barcode' );
		}

		$response = $this->otpravka_client->search_backlog_by_barcode( $barcode );
		if ( ! (bool) ( $response['success'] ?? false ) ) {
			return array(
				'cost' => null,
				'error_code' => (string) ( $response['error_code'] ?? 'lookup_failed' ),
				'http_code' => (int) ( $response['http_code'] ?? 0 ),
			);
		}

		$orders = is_array( $response['orders'] ?? null ) ? $response['orders'] : array();
		$selected = $this->extractor->select_search_result( $orders, $barcode );
		if ( null === $selected ) {
			return array( 'cost' => null, 'error_code' => array() === $orders ? 'not_found' : 'ambiguous_result' );
		}

		return array(
			'cost' => $this->extractor->cost_from_row( $selected, 'backlog_search_after_create' ),
			'error_code' => '',
			'http_code' => (int) ( $response['http_code'] ?? 0 ),
		);
	}
}
