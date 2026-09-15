<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Documents;

defined( 'ABSPATH' ) || exit;

final class ShipmentBinaryDocument {
	public function __construct(
		public readonly string $body,
		public readonly string $content_type,
		public readonly string $filename
	) {
		if ( '' === $body ) {
			throw new \InvalidArgumentException( 'Shipment binary document body must not be empty.' );
		}
		if ( '' === trim( $content_type ) ) {
			throw new \InvalidArgumentException( 'Shipment binary document content type must not be empty.' );
		}
		$filename = trim( $filename );
		if (
			'' === $filename
			|| str_contains( $filename, "\r" )
			|| str_contains( $filename, "\n" )
			|| str_contains( $filename, '/' )
			|| str_contains( $filename, '\\' )
		) {
			throw new \InvalidArgumentException( 'Shipment binary document filename must be safe.' );
		}
	}
}
