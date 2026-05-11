<?php
/**
 * Delivery carrier contract placeholder.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

interface WDC_Carrier {
	public function get_id(): string;

	public function get_title(): string;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_services(): array;
}
