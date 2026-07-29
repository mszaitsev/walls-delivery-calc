<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final readonly class PlaceRegionMatchResult {
	public const EXACT = 'exact';
	public const EMPTY_TYPE_FALLBACK = 'empty_type_fallback';
	public const TYPE_MISMATCH = 'type_mismatch';
	public const NOT_FOUND = 'not_found';

	/**
	 * @param array<int,Location> $matches
	 */
	public function __construct(
		public array $matches,
		public string $resolution
	) {
	}
}
