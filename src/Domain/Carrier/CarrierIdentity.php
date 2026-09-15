<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Carrier;

final class CarrierIdentity {
	public function __construct(
		public readonly string $key,
		public readonly string $name,
		public readonly string $type = 'api',
		public readonly bool $enabled = true
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'key'     => $this->key,
			'name'    => $this->name,
			'type'    => $this->type,
			'enabled' => $this->enabled,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['key'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['type'] ?? 'api' ),
			(bool) ( $data['enabled'] ?? true )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->key ) ) {
			$errors[] = 'key is required';
		}

		if ( '' === trim( $this->name ) ) {
			$errors[] = 'name is required';
		}

		if ( ! in_array( $this->type, array( 'api', 'manual', 'fixed' ), true ) ) {
			$errors[] = 'type must be api, manual, or fixed';
		}

		return $errors;
	}
}
