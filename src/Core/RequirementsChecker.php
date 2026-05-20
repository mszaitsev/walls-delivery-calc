<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

defined( 'ABSPATH' ) || exit;

final class RequirementsChecker {
	private PluginEnvironment $environment;

	public function __construct( PluginEnvironment $environment ) {
		$this->environment = $environment;
	}

	/**
	 * @return array<string, array{ok: bool, label: string, actual: string, required: string}>
	 */
	public function checks(): array {
		return array(
			'php' => array(
				'ok'       => version_compare( PHP_VERSION, '8.4', '>=' ),
				'label'    => 'PHP',
				'actual'   => PHP_VERSION,
				'required' => '8.4+',
			),
			'woocommerce_active' => array(
				'ok'       => class_exists( 'WooCommerce' ),
				'label'    => 'WooCommerce active',
				'actual'   => class_exists( 'WooCommerce' ) ? 'yes' : 'no',
				'required' => 'yes',
			),
			'woocommerce_version' => array(
				'ok'       => '' !== $this->environment->wc_version() && version_compare( $this->environment->wc_version(), '9.0', '>=' ),
				'label'    => 'WooCommerce version',
				'actual'   => '' !== $this->environment->wc_version() ? $this->environment->wc_version() : 'unknown',
				'required' => '9.0+',
			),
			'action_scheduler' => array(
				'ok'       => function_exists( 'as_schedule_single_action' ),
				'label'    => 'Action Scheduler',
				'actual'   => function_exists( 'as_schedule_single_action' ) ? 'available' : 'missing',
				'required' => 'available',
			),
			'hpos' => array(
				'ok'       => class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ),
				'label'    => 'HPOS',
				'actual'   => class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ? 'available' : 'missing',
				'required' => 'available',
			),
		);
	}

	public function passes(): bool {
		foreach ( $this->checks() as $check ) {
			if ( ! $check['ok'] ) {
				return false;
			}
		}

		return true;
	}
}
