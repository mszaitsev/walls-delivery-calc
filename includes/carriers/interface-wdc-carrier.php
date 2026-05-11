<?php
/**
 * Delivery carrier contract placeholder.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

interface WDC_Carrier_Interface {
	public function get_id(): string;

	public function get_title(): string;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_services(): array;

	/**
	 * Return a normalized quote with one or more rates.
	 *
	 * @param array<string, mixed> $package WooCommerce package data.
	 * @param array<string, mixed> $context Additional calculation context.
	 * @return array<string, mixed>
	 */
	public function get_quote( array $package, array $context = array() ): array;
}
