<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Admin;

use WallsShop\WDC\Checkout\Runtime\CheckoutCalculationResult;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || exit;

final class CheckoutSimulationPage {
	private const PAGE_SLUG    = 'wdc-checkout-simulation';
	private const NONCE_ACTION = 'wdc_checkout_simulation';
	private const NONCE_NAME   = 'wdc_checkout_simulation_nonce';

	public function __construct(
		private PluginEnvironment $environment,
		private CheckoutOrchestrator $orchestrator
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( 'woocommerce', esc_html__( 'WDC Checkout Simulation', 'walls-delivery-calc' ), esc_html__( 'WDC Checkout Simulation', 'walls-delivery-calc' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-checkout-simulation', $this->environment->plugin_url() . 'assets/admin/checkout-simulation.css', array(), $this->environment->version() );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = $this->maybe_simulate();
		?>
		<div class="wrap wdc-checkout-simulation">
			<h1><?php echo esc_html__( 'WDC Checkout Simulation', 'walls-delivery-calc' ); ?></h1>
			<form method="post" class="wdc-simulation-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<label>Country <input name="country" value="<?php echo esc_attr( $this->posted( 'country', 'RU' ) ); ?>"></label>
				<label>City <input name="city" value="<?php echo esc_attr( $this->posted( 'city', 'Moscow' ) ); ?>"></label>
				<label>Order total <input name="order_total" type="number" step="0.01" value="<?php echo esc_attr( $this->posted( 'order_total', '1000' ) ); ?>"></label>
				<label>Weight, g <input name="weight" type="number" value="<?php echo esc_attr( $this->posted( 'weight', '1000' ) ); ?>"></label>
				<label>Delivery type <select name="delivery_type">
					<?php foreach ( array( '' => 'all', 'pickup' => 'pickup', 'courier' => 'courier' ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->posted( 'delivery_type', '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<button class="button button-primary" type="submit" name="wdc_checkout_action" value="simulate"><?php echo esc_html__( 'Simulate', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php if ( $result instanceof CheckoutCalculationResult ) : ?>
				<?php $this->render_result( $result ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function maybe_simulate(): ?CheckoutCalculationResult {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return null;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		return $this->orchestrator->calculate( $this->request_from_post(), $this->demo_rules(), 'cheapest', false );
	}

	private function request_from_post(): QuoteRequest {
		$country       = strtoupper( $this->posted( 'country', 'RU' ) );
		$city          = $this->posted( 'city', 'Moscow' );
		$order_total   = Money::from_rubles( $this->posted( 'order_total', '1000' ) );
		$weight        = max( 0, (int) $this->posted( 'weight', '1000' ) );
		$delivery_type = $this->posted( 'delivery_type', '' );
		$item          = new PackageItem( 'DEMO', 'Demo item', 1, $order_total, $order_total, $weight, 10, 10, 10 );

		return new QuoteRequest(
			$country,
			new Address( country_code: $country, city: $city, street: 'Demo street', house: '1', raw_address: $city . ', Demo street 1' ),
			Package::from_items( array( $item ), 0, $order_total, $order_total ),
			'card',
			$order_total,
			date( 'Y-m-d' ),
			array( 'delivery_type' => $delivery_type )
		);
	}

	/**
	 * @return array<int,Rule>
	 */
	private function demo_rules(): array {
		return array(
			new Rule( null, 'Demo promo -500', true, 10, 'rate', 'demo', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 500, RuleOperationBases::RUBLES, true, false ),
		);
	}

	private function render_result( CheckoutCalculationResult $result ): void {
		if ( $result->fallback_used ) {
			echo '<div class="wdc-fallback-warning">Fallback used.</div>';
		}

		echo '<section class="wdc-rate-list">';
		foreach ( $result->rates as $rate ) {
			$this->render_rate( $rate );
		}
		echo '</section>';

		$this->render_block( 'Audit', $result->audit );
		$this->render_block( 'Carrier errors', $result->carrier_errors );
	}

	private function render_rate( DeliveryRate $rate ): void {
		?>
		<div class="wdc-rate-block">
			<h2><?php echo esc_html( $rate->title ); ?></h2>
			<p><?php echo esc_html( $this->money_label( $rate->price ) ); ?></p>
			<?php if ( null !== $rate->crossed_price ) : ?>
				<p><del><?php echo esc_html( $this->money_label( $rate->crossed_price ) ); ?></del></p>
			<?php endif; ?>
			<p><?php echo esc_html( $rate->planned_delivery_comment ); ?></p>
			<?php foreach ( $rate->comments as $comment ) : ?>
				<span class="wdc-rate-comment"><?php echo esc_html( $comment ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param mixed $data
	 */
	private function render_block( string $title, mixed $data ): void {
		?>
		<section class="wdc-audit-block">
			<h2><?php echo esc_html( $title ); ?></h2>
			<pre><?php echo esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '' ); ?></pre>
		</section>
		<?php
	}

	private function posted( string $key, string $default ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	private function money_label( Money $money ): string {
		return number_format( $money->get_rubles(), 2, '.', ' ' ) . ' ' . $money->get_currency();
	}
}
