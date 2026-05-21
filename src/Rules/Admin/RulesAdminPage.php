<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Admin;

use RuntimeException;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleAuditEntry;
use WallsShop\WDC\Rules\Domain\RuleEngineResult;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class RulesAdminPage {
	private const PAGE_SLUG = 'wdc-rules';
	private const NONCE_ACTION = 'wdc_rules_action';
	private const NONCE_NAME = 'wdc_rules_nonce';

	public function __construct(
		private PluginEnvironment $environment,
		private RuleRepository $repository,
		private RuleSimulator $simulator
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Правила', 'walls-delivery-calc' ), esc_html__( 'Правила', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-rules-admin', $this->environment->plugin_url() . 'assets/admin/rules-admin.css', array(), $this->environment->version() );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$simulation = null;
		$message    = $this->handle_post( $simulation );
		$rules      = $this->repository->get_enabled_rules();
		?>
		<div class="wrap wdc-rules-admin">
			<h1><?php echo esc_html__( 'Правила', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<form class="wdc-rules-actions" method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<button class="button" type="submit" name="wdc_rules_action" value="create_demo"><?php echo esc_html__( 'Создать демо-правила', 'walls-delivery-calc' ); ?></button>
				<button class="button" type="submit" name="wdc_rules_action" value="delete_demo"><?php echo esc_html__( 'Удалить демо-правила', 'walls-delivery-calc' ); ?></button>
				<button class="button button-primary" type="submit" name="wdc_rules_action" value="simulate"><?php echo esc_html__( 'Симулировать', 'walls-delivery-calc' ); ?></button>
			</form>

			<table class="widefat striped wdc-rules-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Приоритет', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Название', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Действие', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Операция', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Промо', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Стоп', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $rules ) : ?>
						<tr><td colspan="6"><?php echo esc_html__( 'Правил пока нет.', 'walls-delivery-calc' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rules as $rule ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $rule->priority ); ?></td>
							<td><?php echo esc_html( $rule->name ); ?></td>
							<td><?php echo esc_html( $rule->action_type ); ?></td>
							<td><?php echo esc_html( trim( $rule->operation_type . ' ' . $rule->operation_value . ' ' . $rule->operation_base ) ); ?></td>
							<td><?php echo esc_html( $rule->promo_shipping ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
							<td><?php echo esc_html( $rule->stop_processing ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $simulation instanceof RuleEngineResult ) : ?>
				<?php $this->render_simulation( $simulation ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_simulation( RuleEngineResult $result ): void {
		?>
		<section class="wdc-rules-result">
			<h2><?php echo esc_html__( 'Результат симуляции', 'walls-delivery-calc' ); ?></h2>
			<div class="wdc-rules-result-grid">
				<span><?php echo esc_html__( 'Исходная цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->original_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Зачеркнутая цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->crossed_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Итоговая цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->final_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Отключено', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $result->disabled ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></strong></span>
			</div>
			<?php if ( array() !== $result->comments ) : ?>
				<div class="wdc-rules-comments">
					<?php foreach ( $result->comments as $comment ) : ?>
						<p><?php echo esc_html( $comment ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="wdc-rules-audit">
				<?php foreach ( $result->audit as $entry ) : ?>
					<?php $this->render_audit_entry( $entry ); ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function render_audit_entry( RuleAuditEntry $entry ): void {
		?>
		<div class="wdc-rules-audit-entry <?php echo $entry->applied ? 'is-applied' : 'is-not-applied'; ?>">
			<strong><?php echo esc_html( $entry->rule_name ); ?></strong>
			<span><?php echo esc_html( $entry->action_type ); ?></span>
			<p><?php echo esc_html( $entry->reason ); ?></p>
		</div>
		<?php
	}

	private function handle_post( ?RuleEngineResult &$simulation ): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return '';
		}

		$action = isset( $_POST['wdc_rules_action'] ) ? sanitize_key( wp_unslash( $_POST['wdc_rules_action'] ) ) : '';
		if ( 'create_demo' === $action ) {
			try {
				$count = $this->create_demo_rules();
			} catch ( RuntimeException $exception ) {
				return $exception->getMessage();
			}

			return sprintf( __( 'Создано демо-правил: %d.', 'walls-delivery-calc' ), $count );
		}

		if ( 'delete_demo' === $action ) {
			$this->repository->delete_all();
			return __( 'Демо-правила удалены.', 'walls-delivery-calc' );
		}

		if ( 'simulate' === $action ) {
			$simulation = $this->simulator->simulate( $this->repository->get_enabled_rules() );
			return __( 'Симуляция завершена.', 'walls-delivery-calc' );
		}

		return '';
	}

	private function create_demo_rules(): int {
		$path = $this->environment->plugin_dir() . 'database/demo/rules-demo.json';
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		if ( false === $json ) {
			throw new RuntimeException( 'Demo rules file is not readable.' );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Demo rules file is invalid.' );
		}

		$this->repository->delete_all();
		$count = 0;
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) ) {
				$this->repository->save_rule( Rule::from_array( $item ) );
				++$count;
			}
		}

		return $count;
	}

	private function money_label( mixed $money ): string {
		if ( ! is_object( $money ) || ! method_exists( $money, 'get_rubles' ) ) {
			return '-';
		}

		return number_format( (float) $money->get_rubles(), 2, '.', ' ' ) . ' ' . $money->get_currency();
	}
}
