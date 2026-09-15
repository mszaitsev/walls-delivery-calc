<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Common;

final class Money {
	public function __construct(
		private int $amount_kopecks = 0,
		private string $currency = 'RUB'
	) {
	}

	public static function from_rubles( float|int|string $rubles, string $currency = 'RUB' ): self {
		return new self( MoneyParser::rubles_to_kopecks( (string) $rubles ), $currency );
	}

	public static function from_kopecks( int $kopecks, string $currency = 'RUB' ): self {
		return new self( $kopecks, $currency );
	}

	public function get_kopecks(): int {
		return $this->amount_kopecks;
	}

	public function get_rubles(): float {
		return $this->amount_kopecks / 100;
	}

	public function get_currency(): string {
		return $this->currency;
	}

	public function add( Money $other ): self {
		$this->assert_same_currency( $other );

		return new self( $this->amount_kopecks + $other->amount_kopecks, $this->currency );
	}

	public function subtract( Money $other ): self {
		$this->assert_same_currency( $other );

		return new self( max( 0, $this->amount_kopecks - $other->amount_kopecks ), $this->currency );
	}

	public function multiply( float $factor ): self {
		return new self( max( 0, (int) round( $this->amount_kopecks * $factor ) ), $this->currency );
	}

	public function max( Money $other ): self {
		$this->assert_same_currency( $other );

		return $this->amount_kopecks >= $other->amount_kopecks ? $this : $other;
	}

	public function is_zero(): bool {
		return 0 === $this->amount_kopecks;
	}

	/**
	 * @return array{amount_kopecks:int,currency:string}
	 */
	public function to_array(): array {
		return array(
			'amount_kopecks' => $this->amount_kopecks,
			'currency'       => $this->currency,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['amount_kopecks'] ?? 0 ),
			(string) ( $data['currency'] ?? 'RUB' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->currency ) ) {
			$errors[] = 'currency is required';
		}

		if ( $this->amount_kopecks < 0 ) {
			$errors[] = 'amount_kopecks must be greater than or equal to 0';
		}

		return $errors;
	}

	private function assert_same_currency( Money $other ): void {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException( 'Money currency mismatch.' );
		}
	}
}
