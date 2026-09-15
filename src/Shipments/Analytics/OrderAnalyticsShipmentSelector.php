<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class OrderAnalyticsShipmentSelector {
	/** @var array<string,int> */
	private array $skip_counts = array(
		'no_selected_delivery_identity' => 0,
		'no_matching_created_shipment' => 0,
		'ambiguous_matching_shipments' => 0,
	);

	public function __construct(
		private OrderSelectedDeliveryIdentityResolver $identity_resolver,
		private CreatedShipmentIdentityResolver $created_identity_resolver
	) {
	}

	public function reset_diagnostics(): void {
		foreach ( $this->skip_counts as $reason => $_count ) {
			$this->skip_counts[ $reason ] = 0;
		}
	}

	/**
	 * @return array<string,int>
	 */
	public function skip_counts(): array {
		return $this->skip_counts;
	}

	/**
	 * @param array<string,mixed> $shipments
	 */
	public function select( object $order, array $shipments ): ?SelectedAnalyticsShipment {
		$identity = $this->identity_resolver->resolve( $order );
		if ( null === $identity || '' === $identity->carrier_key ) {
			++$this->skip_counts['no_selected_delivery_identity'];

			return null;
		}

		$created = $this->created_shipments( $shipments );
		if ( '' !== $identity->service_key ) {
			$exact = $this->matching_shipments( $created, $identity->carrier_key, $identity->service_key );
			if ( 1 === count( $exact ) ) {
				return $exact[0];
			}
			if ( count( $exact ) > 1 ) {
				++$this->skip_counts['ambiguous_matching_shipments'];

				return null;
			}
		}

		$carrier_matches = $this->matching_shipments( $created, $identity->carrier_key, null );
		if ( 1 === count( $carrier_matches ) ) {
			return $carrier_matches[0];
		}
		if ( count( $carrier_matches ) > 1 ) {
			++$this->skip_counts['ambiguous_matching_shipments'];

			return null;
		}

		++$this->skip_counts['no_matching_created_shipment'];

		return null;
	}

	/**
	 * @param array<string,mixed> $shipments
	 * @return array<int,SelectedAnalyticsShipment>
	 */
	private function created_shipments( array $shipments ): array {
		$created = array();
		foreach ( $shipments as $shipment_key => $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$created_identity = $this->created_identity_resolver->resolve( $shipment );
			if ( null === $created_identity ) {
				continue;
			}
			$carrier_key = $this->normalize_key( (string) ( $shipment['carrier_key'] ?? $shipment_key ) );
			if ( '' === $carrier_key ) {
				continue;
			}
			$created[] = new SelectedAnalyticsShipment(
				(string) $shipment_key,
				$shipment,
				$carrier_key,
				$this->normalize_key( (string) ( $shipment['service_key'] ?? '' ) ),
				$created_identity
			);
		}

		return $created;
	}

	/**
	 * @param array<int,SelectedAnalyticsShipment> $shipments
	 * @return array<int,SelectedAnalyticsShipment>
	 */
	private function matching_shipments( array $shipments, string $carrier_key, ?string $service_key ): array {
		return array_values(
			array_filter(
				$shipments,
				static fn( SelectedAnalyticsShipment $shipment ): bool =>
					$shipment->carrier_key === $carrier_key
					&& ( null === $service_key || $shipment->service_key === $service_key )
			)
		);
	}

	private function normalize_key( string $value ): string {
		return function_exists( 'sanitize_key' )
			? sanitize_key( $value )
			: strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}
}
