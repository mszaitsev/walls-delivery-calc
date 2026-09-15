<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Admin;

use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class RuleAdminContext {
	public function __construct(
		public readonly string $target_type,
		public readonly string $target_value,
		public readonly string $page_slug,
		public readonly string $return_url,
		public readonly string $list_title,
		public readonly string $form_title,
		public readonly string $empty_message,
		public readonly bool $allow_simulation = true
	) {
	}

	public static function default(): self {
		return new self(
			RuleRepository::TARGET_DEFAULT,
			'',
			'wdc-rules',
			function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wdc-rules' ) : 'admin.php?page=wdc-rules',
			'Дефолтные правила расчета',
			'Правило расчета',
			'Дефолтные правила пока не созданы.',
			true
		);
	}

	public function is_default(): bool {
		return RuleRepository::TARGET_DEFAULT === $this->target_type && '' === $this->target_value;
	}
}
