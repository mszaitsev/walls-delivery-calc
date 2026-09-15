<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Admin;

defined( 'ABSPATH' ) || exit;

/** Unwinds the write lock before WordPress terminates an AJAX request. */
final class LocationAdminJsonResponse extends \Exception {
	public function __construct( public readonly array $data, public readonly bool $success ) {
		parent::__construct( 'Deferred locations admin response.' );
	}
}
