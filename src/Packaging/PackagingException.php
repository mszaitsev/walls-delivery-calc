<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

defined( 'ABSPATH' ) || exit;

final class PackagingException extends \RuntimeException {
	public function __construct( public readonly string $safe_code, string $message = 'Не удалось сформировать допустимые грузоместа.' ) {
		parent::__construct( $message );
	}
}
