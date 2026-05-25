<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Admin;

use WallsShop\WDC\Admin\AdminMenu;
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
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Симулятор checkout', 'walls-delivery-calc' ), esc_html__( 'Симулятор checkout', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-checkout-simulation', $this->environment->plugin_url() . 'assets/admin/checkout-simulation.css', array(), $this->environment->version() );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$result = $this->maybe_simulate();
		?>
		<div class="wrap wdc-checkout-simulation">
			<h1><?php echo esc_html__( 'Симулятор checkout', 'walls-delivery-calc' ); ?></h1>
			<form method="post" class="wdc-simulation-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<label><?php echo esc_html__( 'Страна', 'walls-delivery-calc' ); ?> <input name="country" value="<?php echo esc_attr( $this->posted( 'country', 'RU' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Город', 'walls-delivery-calc' ); ?> <input name="city" value="<?php echo esc_attr( $this->posted( 'city', 'Москва' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Сумма заказа', 'walls-delivery-calc' ); ?> <input name="order_total" type="number" step="0.01" value="<?php echo esc_attr( $this->posted( 'order_total', '1000' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?> <input name="weight" type="number" value="<?php echo esc_attr( $this->posted( 'weight', '1000' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Тип доставки', 'walls-delivery-calc' ); ?> <select name="delivery_type">
					<?php foreach ( array( '' => __( 'все', 'walls-delivery-calc' ), 'pickup' => __( 'пункт выдачи', 'walls-delivery-calc' ), 'courier' => __( 'курьер', 'walls-delivery-calc' ) ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->posted( 'delivery_type', '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<button class="button button-primary" type="submit" name="wdc_checkout_action" value="simulate"><?php echo esc_html__( 'Симулировать', 'walls-delivery-calc' ); ?></button>
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

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return null;
		}

		return $this->orchestrator->calculate( $this->request_from_post(), $this->sample_rules(), 'cheapest', false );
	}

	private function request_from_post(): QuoteRequest {
		$country       = strtoupper( $this->posted( 'country', 'RU' ) );
		$city          = $this->posted( 'city', 'Москва' );
		$order_total   = Money::from_rubles( $this->posted( 'order_total', '1000' ) );
		$weight        = max( 0, (int) $this->posted( 'weight', '1000' ) );
		$delivery_type = $this->posted( 'delivery_type', '' );
		$item          = new PackageItem( 'DEMO', 'Тестовый товар', 1, $order_total, $order_total, $weight, 10, 10, 10 );

		return new QuoteRequest(
			$country,
			new Address( country_code: $country, city: $city, street: 'Тестовая улица', house: '1', raw_address: $city . ', Тестовая улица 1' ),
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
	private function sample_rules(): array {
		return array(
			new Rule( null, 'Sample promo -500', true, 10, 'rate', 'sample', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 500, RuleOperationBases::RUBLES, true, false ),
		);
	}

	private function render_result( CheckoutCalculationResult $result ): void {
		if ( $result->fallback_used ) {
			echo '<div class="wdc-fallback-warning">' . esc_html__( 'Использован резервный тариф.', 'walls-delivery-calc' ) . '</div>';
		}

		echo '<section class="wdc-rate-list">';
		foreach ( $result->rates as $rate ) {
			$this->render_rate( $rate );
		}
		echo '</section>';

		$this->render_block( __( 'Аудит', 'walls-delivery-calc' ), $result->audit );
		$this->render_block( __( 'Ошибки перевозчиков', 'walls-delivery-calc' ), $result->carrier_errors );
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
