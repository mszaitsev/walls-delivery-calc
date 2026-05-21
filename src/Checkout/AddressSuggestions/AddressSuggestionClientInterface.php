<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

defined( 'ABSPATH' ) || exit;

interface AddressSuggestionClientInterface {
	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	public function suggest( string $stage, string $query, array $context = array() ): array;
}
