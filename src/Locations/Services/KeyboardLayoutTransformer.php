<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

defined( 'ABSPATH' ) || exit;

final class KeyboardLayoutTransformer {
	/** @var array<string,string> */
	private const LATIN_TO_CYRILLIC = array(
		'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е', 'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ', 'p' => 'з',
		'[' => 'х', ']' => 'ъ', 'a' => 'ф', 's' => 'ы', 'd' => 'в', 'f' => 'а', 'g' => 'п', 'h' => 'р', 'j' => 'о', 'k' => 'л',
		'l' => 'д', ';' => 'ж', "'" => 'э', 'z' => 'я', 'x' => 'ч', 'c' => 'с', 'v' => 'м', 'b' => 'и', 'n' => 'т', 'm' => 'ь',
		',' => 'б', '.' => 'ю', '`' => 'ё',
	);

	public function latin_to_cyrillic_layout( string $value ): string {
		return $this->swap( $value, self::LATIN_TO_CYRILLIC );
	}

	public function cyrillic_to_latin_layout( string $value ): string {
		return $this->swap( $value, array_flip( self::LATIN_TO_CYRILLIC ) );
	}

	/**
	 * @return array<int,string>
	 */
	public function variants( string $query ): array {
		$variants = array( $query, $this->latin_to_cyrillic_layout( $query ), $this->cyrillic_to_latin_layout( $query ) );
		$unique   = array();

		foreach ( $variants as $variant ) {
			if ( '' !== trim( $variant ) && ! in_array( $variant, $unique, true ) ) {
				$unique[] = $variant;
			}
		}

		return $unique;
	}

	/**
	 * @param array<string,string> $map
	 */
	private function swap( string $value, array $map ): string {
		$result = '';
		$chars  = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return $value;
		}

		foreach ( $chars as $char ) {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $char, 'UTF-8' ) : strtolower( $char );
			$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $lower, 'UTF-8' ) : strtoupper( $lower );
			$mapped = $map[ $lower ] ?? $char;
			$result .= $char === $upper ? ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $mapped, 'UTF-8' ) : strtoupper( $mapped ) ) : $mapped;
		}

		return $result;
	}
}
