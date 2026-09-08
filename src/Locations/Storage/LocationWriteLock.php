<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class LocationWriteLock {
	private const NAME = 'wdc_locations_write_lock';
	private \wpdb $db;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	public function run( callable $operation ): mixed {
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', self::NAME ) ) ) {
			throw new RuntimeException( 'Операция базы населённых пунктов уже выполняется или блокировка недоступна.', 409 );
		}
		try {
			return $operation();
		} finally {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', self::NAME ) );
		}
	}
}
