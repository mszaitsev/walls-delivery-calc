<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Address;

final class AddressNormalizationResult {
	public function __construct(
		public readonly string $input,
		public readonly Address $address,
		public readonly bool $success = false,
		public readonly float $confidence = 0.0,
		public readonly string $source = 'manual',
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly array $debug = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'input'         => $this->input,
			'address'       => $this->address->to_array(),
			'success'       => $this->success,
			'confidence'    => $this->confidence,
			'source'        => $this->source,
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
			'debug'         => $this->debug,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['input'] ?? '' ),
			Address::from_array( is_array( $data['address'] ?? null ) ? $data['address'] : array() ),
			(bool) ( $data['success'] ?? false ),
			(float) ( $data['confidence'] ?? 0.0 ),
			(string) ( $data['source'] ?? 'manual' ),
			(string) ( $data['error_code'] ?? '' ),
			(string) ( $data['error_message'] ?? '' ),
			is_array( $data['debug'] ?? null ) ? $data['debug'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( ! in_array( $this->source, array( 'fias', 'dadata', 'fallback', 'manual' ), true ) ) {
			$errors[] = 'source must be fias, dadata, fallback, or manual';
		}

		if ( $this->confidence < 0.0 || $this->confidence > 1.0 ) {
			$errors[] = 'confidence must be between 0 and 1';
		}

		return array_merge( $errors, $this->address->validate() );
	}
}
