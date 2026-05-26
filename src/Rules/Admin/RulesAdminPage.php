<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Admin;

use DateTimeImmutable;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleAuditEntry;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEngineResult;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

defined( 'ABSPATH' ) || exit;

final class RulesAdminPage {
	private const PAGE_SLUG = 'wdc-rules';
	private const NONCE_ACTION = 'wdc_rules_action';
	private const NONCE_NAME = 'wdc_rules_nonce';

	/** @var array<int,string> */
	private array $errors = array();

	private ?Rule $form_rule = null;

	private ?RuleEngineResult $simulation = null;

	/** @var array<string,mixed> */
	private array $simulation_input = array();

	/** @var array<string,mixed> */
	private array $service_simulation = array();

	/** @var callable|null */
	private $service_simulation_runner = null;

	private ?RuleAdminContext $context = null;

	public function __construct(
		private PluginEnvironment $environment,
		private RuleRepository $repository,
		private RuleSimulator $simulator,
		private ?SettingsRepository $settings = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wdc_rules_location_search', array( $this, 'ajax_location_search' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Правила', 'walls-delivery-calc' ), esc_html__( 'Правила', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) && ! str_contains( $hook_suffix, 'wdc-delivery-services' ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-rules-admin', $this->environment->plugin_url() . 'assets/admin/rules-admin.css', array(), $this->environment->version() );
		wp_enqueue_script( 'wdc-rules-admin', $this->environment->plugin_url() . 'assets/admin/rules-admin.js', array(), $this->environment->version(), true );
		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script( 'wdc-rules-admin', 'wdcRulesAdmin', $this->js_config() );
		}
	}

	public function render_page(): void {
		$this->context = RuleAdminContext::default();
		$this->service_simulation_runner = null;
		if ( 'packaging' === $this->current_tab() ) {
			$this->render_packaging_page();
			return;
		}
		$this->render_full_for_current_context();
	}

	public function render_for_context( RuleAdminContext $context ): void {
		$this->context = $context;
		$this->render_full_for_current_context();
	}

	public function set_service_simulation_runner( ?callable $runner ): void {
		$this->service_simulation_runner = $runner;
	}

	public function render_embedded_for_context( RuleAdminContext $context ): void {
		$this->context = $context;
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$data = $this->prepare_current_context_render();
		?>
		<div class="wdc-rules-admin wdc-rules-admin-embedded">
			<?php $this->render_context_body( $data['rules'], $data['edit_rule'] ); ?>
		</div>
		<?php
	}

	private function render_full_for_current_context(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$data = $this->prepare_current_context_render();
		?>
		<div class="wrap wdc-rules-admin">
			<h1><?php echo esc_html( $this->context()->list_title ); ?></h1>
			<?php $this->render_full_tabs( 'rules' ); ?>
			<p class="description"><?php echo esc_html( $this->context()->is_default() ? __( 'Эти правила применяются по умолчанию для служб доставки, у которых нет включенных собственных правил.', 'walls-delivery-calc' ) : __( 'Эти правила применяются только для выбранной службы доставки. Симуляция на этой вкладке не подмешивает дефолтные правила.', 'walls-delivery-calc' ) ); ?></p>
			<?php $this->render_context_body( $data['rules'], $data['edit_rule'] ); ?>
		</div>
		<?php
	}

	private function render_full_tabs( string $active ): void {
		?>
		<nav class="nav-tab-wrapper">
			<a class="nav-tab <?php echo 'rules' === $active ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Правила', 'walls-delivery-calc' ); ?></a>
			<a class="nav-tab <?php echo 'packaging' === $active ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=packaging' ) ); ?>"><?php echo esc_html__( 'Упаковка', 'walls-delivery-calc' ); ?></a>
		</nav>
		<?php
	}

	private function current_tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rules';
	}

	/**
	 * @return array{rules:array<int,Rule>,edit_rule:?Rule}
	 */
	private function prepare_current_context_render(): array {
		$this->handle_post();
		$this->load_simulation_from_request();

		$rules     = $this->repository->get_all_rules_for_target( $this->context()->target_type, $this->context()->target_value );
		$edit_rule = $this->form_rule;
		if ( null === $edit_rule ) {
			$edit_id = isset( $_GET['edit_rule'] ) ? absint( wp_unslash( $_GET['edit_rule'] ) ) : 0;
			if ( $edit_id > 0 ) {
				$loaded = $this->repository->get_rule( $edit_id );
				if ( $this->rule_matches_context( $loaded ) ) {
					$edit_rule = $loaded;
				}
			}
		}

		return array(
			'rules'     => $rules,
			'edit_rule' => $edit_rule,
		);
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	private function render_context_body( array $rules, ?Rule $edit_rule ): void {
		?>
		<?php $this->render_notices(); ?>

		<div class="wdc-rules-toolbar">
			<a class="button button-primary" href="<?php echo esc_url( $this->page_url( array( 'new_rule' => 1 ) ) ); ?>"><?php echo esc_html__( 'Добавить правило', 'walls-delivery-calc' ); ?></a>
			<a class="button" href="#wdc-rules-simulation"><?php echo esc_html__( 'Проверить правила', 'walls-delivery-calc' ); ?></a>
		</div>

		<section class="wdc-rules-scope">
			<strong><?php echo esc_html( $this->context()->list_title ); ?></strong>
			<span><?php echo esc_html( sprintf( 'target_type=%s, target_value=%s. Условия внутри каждой группы и сочетание групп настраиваются в блоке «Условия применения».', $this->context()->target_type, '' === $this->context()->target_value ? 'empty' : $this->context()->target_value ) ); ?></span>
		</section>

		<?php $this->render_rules_table( $rules ); ?>

		<?php if ( $this->should_show_form( $edit_rule ) ) : ?>
			<?php $this->render_rule_form( $edit_rule ?? $this->empty_rule() ); ?>
		<?php endif; ?>

		<?php if ( $this->context()->allow_simulation ) : ?>
			<?php $this->render_simulation_form(); ?>
		<?php endif; ?>

		<?php if ( array() !== $this->service_simulation ) : ?>
			<?php $this->render_service_simulation( $this->service_simulation ); ?>
		<?php endif; ?>

		<?php if ( $this->simulation instanceof RuleEngineResult ) : ?>
			<?php $this->render_simulation( $this->simulation ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_notices(): void {
		$notice = isset( $_GET['wdc_rules_notice'] ) ? sanitize_key( wp_unslash( $_GET['wdc_rules_notice'] ) ) : '';
		$messages = array(
			'saved'      => __( 'Правило сохранено.', 'walls-delivery-calc' ),
			'deleted'    => __( 'Правило удалено.', 'walls-delivery-calc' ),
			'toggled'    => __( 'Статус правила изменен.', 'walls-delivery-calc' ),
			'duplicated' => __( 'Копия правила создана и отключена.', 'walls-delivery-calc' ),
			'moved'      => __( 'Порядок правил изменен.', 'walls-delivery-calc' ),
			'simulated'  => __( 'Симуляция завершена.', 'walls-delivery-calc' ),
			'copied'     => __( 'Дефолтные правила скопированы в службу.', 'walls-delivery-calc' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php
		}

		if ( array() !== $this->errors ) {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'Правило не сохранено:', 'walls-delivery-calc' ); ?></p>
				<ul>
					<?php foreach ( $this->errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	private function render_rules_table( array $rules ): void {
		?>
		<table class="widefat striped wdc-rules-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Вкл.', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Порядок', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Название', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Условия', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Действие', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Значение', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Промо', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Стоп', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Действия', 'walls-delivery-calc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rules ) : ?>
					<tr><td colspan="9"><?php echo esc_html( $this->context()->empty_message ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $index => $rule ) : ?>
					<tr draggable="true" data-rule-row data-rule-id="<?php echo esc_attr( (string) $rule->id ); ?>">
						<td><?php echo esc_html( $rule->enabled ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
						<td><span class="wdc-drag-handle" aria-hidden="true">↕</span><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $rule->name ); ?></strong>
							<small><?php echo esc_html( $this->context()->is_default() ? __( 'Дефолтные правила', 'walls-delivery-calc' ) : __( 'Правила службы', 'walls-delivery-calc' ) ); ?></small>
						</td>
						<td><?php echo esc_html( $this->conditions_summary( $rule ) ); ?></td>
						<td><?php echo esc_html( $this->action_label( $rule->action_type ) ); ?></td>
						<td><?php echo esc_html( $this->operation_summary( $rule ) ); ?></td>
						<td><?php echo esc_html( $rule->promo_shipping ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
						<td><?php echo esc_html( $rule->stop_processing ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
						<td class="wdc-rules-row-actions">
							<?php $this->render_row_action( $rule, $rule->enabled ? 'toggle_rule' : 'toggle_rule', $rule->enabled ? __( 'Выключить', 'walls-delivery-calc' ) : __( 'Включить', 'walls-delivery-calc' ) ); ?>
							<a class="button button-small" href="<?php echo esc_url( $this->page_url( array( 'edit_rule' => $rule->id ) ) ); ?>"><?php echo esc_html__( 'Изменить', 'walls-delivery-calc' ); ?></a>
							<?php $this->render_row_action( $rule, 'duplicate_rule', __( 'Дублировать', 'walls-delivery-calc' ) ); ?>
							<?php $this->render_row_action( $rule, 'move_up', __( 'Вверх', 'walls-delivery-calc' ) ); ?>
							<?php $this->render_row_action( $rule, 'move_down', __( 'Вниз', 'walls-delivery-calc' ) ); ?>
							<?php $this->render_row_action( $rule, 'delete_rule', __( 'Удалить', 'walls-delivery-calc' ), 'button-link-delete wdc-rules-delete' ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" class="wdc-reorder-form" data-reorder-form>
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_rules_action" value="reorder_rules">
			<input type="hidden" name="ordered_rule_ids" value="" data-ordered-rule-ids>
		</form>
		<?php
	}

	private function render_row_action( Rule $rule, string $action, string $label, string $class = '' ): void {
		?>
		<form method="post" class="wdc-inline-form">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_rules_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule->id ); ?>">
			<button class="button button-small <?php echo esc_attr( $class ); ?>" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function render_rule_form( Rule $rule ): void {
		?>
		<section class="wdc-rules-card" id="wdc-rule-form">
			<h2><?php echo esc_html( null === $rule->id ? __( 'Добавить правило', 'walls-delivery-calc' ) : __( 'Изменить правило', 'walls-delivery-calc' ) ); ?></h2>
			<form method="post" class="wdc-rule-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="wdc_rules_action" value="save_rule">
				<input type="hidden" name="rule_id" value="<?php echo esc_attr( (string) $rule->id ); ?>">

				<div class="wdc-rule-grid">
					<label>
						<span><?php echo esc_html__( 'Название', 'walls-delivery-calc' ); ?></span>
						<input class="regular-text" type="text" name="name" value="<?php echo esc_attr( $rule->name ); ?>" required>
					</label>
					<label class="wdc-checkbox">
						<input type="checkbox" name="enabled" value="1" <?php echo $rule->enabled ? 'checked' : ''; ?>>
						<span><?php echo esc_html__( 'Включено', 'walls-delivery-calc' ); ?></span>
					</label>
					<label class="wdc-checkbox">
						<input type="checkbox" name="promo_shipping" value="1" <?php echo $rule->promo_shipping ? 'checked' : ''; ?>>
						<span><?php echo esc_html__( 'Промо-доставка', 'walls-delivery-calc' ); ?></span>
					</label>
					<label class="wdc-checkbox">
						<input type="checkbox" name="stop_processing" value="1" <?php echo $rule->stop_processing ? 'checked' : ''; ?>>
						<span><?php echo esc_html__( 'Остановить дальнейшую обработку', 'walls-delivery-calc' ); ?></span>
					</label>
				</div>

				<h3><?php echo esc_html__( 'Действие', 'walls-delivery-calc' ); ?></h3>
				<div class="wdc-rule-grid wdc-operation-fields" data-operation-fields>
					<label>
						<span><?php echo esc_html__( 'Тип действия', 'walls-delivery-calc' ); ?></span>
						<select name="action_type" data-action-type>
							<?php foreach ( RuleActionTypes::all() as $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule->action_type, $value ); ?>><?php echo esc_html( $this->action_label( $value ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label data-operation-type-field>
						<span><?php echo esc_html__( 'Операция', 'walls-delivery-calc' ); ?></span>
						<select name="operation_type" data-operation-control>
							<?php foreach ( RuleOperationTypes::all() as $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule->operation_type, $value ); ?>><?php echo esc_html( $this->operation_type_label( $value ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label data-operation-value-field>
						<span><?php echo esc_html__( 'Значение', 'walls-delivery-calc' ); ?></span>
						<input type="text" inputmode="decimal" name="operation_value" value="<?php echo esc_attr( (string) $rule->operation_value ); ?>" data-operation-control>
					</label>
					<label data-operation-base-field>
						<span><?php echo esc_html__( 'База', 'walls-delivery-calc' ); ?></span>
						<select name="operation_base" data-operation-control data-operation-base>
							<?php foreach ( RuleOperationBases::all() as $value ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" data-base-kind="<?php echo esc_attr( in_array( $value, RuleOperationBases::day_bases(), true ) ? 'days' : 'money' ); ?>" <?php selected( $rule->operation_base, $value ); ?>><?php echo esc_html( $this->operation_base_label( $value ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="wdc-operation-comment" data-operation-comment>
						<span><?php echo esc_html__( 'Комментарий', 'walls-delivery-calc' ); ?></span>
						<textarea name="operation_text" rows="3"><?php echo esc_textarea( $rule->operation_text ); ?></textarea>
					</label>
				</div>

				<h3><?php echo esc_html__( 'Условия применения', 'walls-delivery-calc' ); ?></h3>
				<?php $this->render_group_logic_fields( $rule ); ?>
				<div class="wdc-condition-groups" data-conditions>
					<?php $condition_index = 0; ?>
					<?php foreach ( $this->conditions_by_group_for_form( $rule ) as $group => $conditions ) : ?>
						<section class="wdc-condition-group" data-condition-group-block="<?php echo esc_attr( (string) $group ); ?>">
							<h4><?php echo esc_html( sprintf( __( 'Условие %d', 'walls-delivery-calc' ), $group ) ); ?></h4>
							<div class="wdc-condition-list" data-condition-list>
								<?php foreach ( $conditions as $condition ) : ?>
									<?php $this->render_condition_row( $condition, $condition_index++, (int) $group ); ?>
								<?php endforeach; ?>
							</div>
							<?php $this->render_condition_row( new RuleCondition( null, null, (int) $group, '', '', '', null, array() ), -1, (int) $group, true ); ?>
							<button class="button" type="button" data-add-condition data-condition-group="<?php echo esc_attr( (string) $group ); ?>"><?php echo esc_html( sprintf( __( 'Добавить условие в Условие %d', 'walls-delivery-calc' ), $group ) ); ?></button>
						</section>
					<?php endforeach; ?>
				</div>

				<p class="submit">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить правило', 'walls-delivery-calc' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php echo esc_html__( 'Отмена', 'walls-delivery-calc' ); ?></a>
				</p>
			</form>
		</section>
		<?php
	}

	private function render_condition_row( RuleCondition $condition, int $index, int $group, bool $template = false ): void {
		$value_json = wp_json_encode( $condition->value_json );
		$name_prefix = $template ? 'conditions[__index__]' : 'conditions[' . (string) $index . ']';
		?>
		<div class="wdc-condition-row <?php echo $template ? 'is-template' : ''; ?>" <?php echo $template ? 'data-condition-template' : 'data-condition-row'; ?> data-condition-value="<?php echo esc_attr( $this->condition_value_payload( $condition ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[condition_group]" value="<?php echo esc_attr( (string) $group ); ?>" data-condition-group <?php disabled( $template ); ?>>
			<label>
				<span><?php echo esc_html__( 'Тип условия', 'walls-delivery-calc' ); ?></span>
				<select name="<?php echo esc_attr( $name_prefix ); ?>[condition_type]" <?php disabled( $template ); ?>>
					<option value=""><?php echo esc_html__( 'Не выбрано', 'walls-delivery-calc' ); ?></option>
					<?php foreach ( RuleConditionTypes::all() as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $condition->condition_type, $value ); ?>><?php echo esc_html( $this->condition_type_label( $value ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php echo esc_html__( 'Оператор', 'walls-delivery-calc' ); ?></span>
				<select name="<?php echo esc_attr( $name_prefix ); ?>[operator]" data-condition-operator data-selected-operator="<?php echo esc_attr( $condition->operator ); ?>" <?php disabled( $template ); ?>></select>
			</label>
			<div class="wdc-condition-value" data-condition-value-control></div>
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[value_text]" value="<?php echo esc_attr( $condition->value_text ); ?>" data-value-text <?php disabled( $template ); ?>>
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[value_number]" value="<?php echo esc_attr( null === $condition->value_number ? '' : (string) $condition->value_number ); ?>" data-value-number <?php disabled( $template ); ?>>
			<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[value_json]" value="<?php echo esc_attr( false === $value_json ? '{}' : $value_json ); ?>" data-value-json <?php disabled( $template ); ?>>
			<button class="button" type="button" data-remove-condition <?php disabled( $template ); ?>><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
		</div>
		<?php
	}

	private function render_simulation_form(): void {
		if ( ! $this->context()->is_default() && is_callable( $this->service_simulation_runner ) ) {
			$this->render_service_simulation_form();
			return;
		}

		$input = $this->simulation_input + $this->default_simulation_input();
		?>
		<section class="wdc-rules-card" id="wdc-rules-simulation">
			<h2><?php echo esc_html__( 'Проверить правила', 'walls-delivery-calc' ); ?></h2>
			<form method="post" class="wdc-simulation-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="wdc_rules_action" value="simulate">
				<div class="wdc-rule-grid">
					<label><span><?php echo esc_html__( 'Исходная цена доставки', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[delivery_price]" value="<?php echo esc_attr( (string) $input['delivery_price'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Исходный срок доставки', 'walls-delivery-calc' ); ?></span><input type="number" min="0" name="simulation[delivery_days]" value="<?php echo esc_attr( (string) $input['delivery_days'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Сумма заказа', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[order_total]" value="<?php echo esc_attr( (string) $input['order_total'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?></span><input type="number" name="simulation[weight]" value="<?php echo esc_attr( (string) $input['weight'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Страна', 'walls-delivery-calc' ); ?></span><?php $this->render_select( 'simulation[country]', $this->country_options(), (string) $input['country'] ); ?></label>
					<label><span><?php echo esc_html__( 'Город', 'walls-delivery-calc' ); ?></span><input type="text" name="simulation[city]" value="<?php echo esc_attr( (string) $input['city'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'FIAS ID населенного пункта', 'walls-delivery-calc' ); ?></span><input type="text" name="simulation[location_fias_id]" value="<?php echo esc_attr( (string) $input['location_fias_id'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Тип доставки', 'walls-delivery-calc' ); ?></span><?php $this->render_select( 'simulation[delivery_type]', $this->condition_schema()->delivery_type_options(), (string) $input['delivery_type'] ); ?></label>
					<label><span><?php echo esc_html__( 'Способ оплаты', 'walls-delivery-calc' ); ?></span><?php $this->render_select( 'simulation[payment_method]', $this->payment_method_options(), (string) $input['payment_method'] ); ?></label>
					<label><span><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[length_cm]" value="<?php echo esc_attr( (string) $input['length_cm'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[width_cm]" value="<?php echo esc_attr( (string) $input['width_cm'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[height_cm]" value="<?php echo esc_attr( (string) $input['height_cm'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Объем, куб.м.', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[volume_m3]" value="<?php echo esc_attr( (string) $input['volume_m3'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Дата', 'walls-delivery-calc' ); ?></span><input type="date" name="simulation[date]" value="<?php echo esc_attr( (string) $input['date'] ); ?>"></label>
				</div>
				<p class="submit"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Симулировать расчет', 'walls-delivery-calc' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	private function render_simulation( RuleEngineResult $result ): void {
		$original_days = DateRange::single( max( 0, (int) ( $this->simulation_input['delivery_days'] ?? $this->default_simulation_input()['delivery_days'] ) ) );
		$final_days    = $result->final_delivery_days ?? $original_days;
		?>
		<section class="wdc-rules-result">
			<h2><?php echo esc_html__( 'Результат проверки', 'walls-delivery-calc' ); ?></h2>
			<div class="wdc-rules-result-grid">
				<span><?php echo esc_html__( 'Исходная цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->original_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Зачеркнутая цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->crossed_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Итоговая цена', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->money_label( $result->final_price ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Исходный срок', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->date_range_label( $original_days ) ); ?></strong></span>
				<span><?php echo esc_html__( 'Итоговый срок', 'walls-delivery-calc' ); ?><strong><?php echo esc_html( $this->date_range_label( $final_days ) ); ?></strong></span>
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
			<span><?php echo esc_html( $entry->applied ? __( 'применено', 'walls-delivery-calc' ) : __( 'не применено', 'walls-delivery-calc' ) ); ?></span>
			<p><?php echo esc_html( $entry->reason ); ?></p>
		</div>
		<?php
	}

	private function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			$this->errors[] = __( 'Недостаточно прав или истек срок nonce.', 'walls-delivery-calc' );
			return;
		}

		$action = isset( $_POST['wdc_rules_action'] ) ? sanitize_key( wp_unslash( $_POST['wdc_rules_action'] ) ) : '';

		if ( 'save_rule' === $action ) {
			$this->save_rule_action();
			return;
		}

		if ( 'delete_rule' === $action ) {
			$this->delete_rule_action();
			return;
		}

		if ( 'toggle_rule' === $action ) {
			$this->toggle_rule_action();
			return;
		}

		if ( 'duplicate_rule' === $action ) {
			$this->duplicate_rule_action();
			return;
		}

		if ( 'move_up' === $action || 'move_down' === $action ) {
			$this->move_rule_action( $action );
			return;
		}

		if ( 'reorder_rules' === $action ) {
			$this->reorder_rules_action();
			return;
		}

		if ( 'simulate' === $action ) {
			$this->simulation_input = $this->sanitize_simulation_input();
			if ( ! $this->context()->is_default() && is_callable( $this->service_simulation_runner ) ) {
				$rules = $this->repository->get_rules_for_target( $this->context()->target_type, $this->context()->target_value );
				if ( array() === $rules ) {
					$this->errors[] = __( 'Для службы не настроены собственные правила.', 'walls-delivery-calc' );
				}
				$this->service_simulation = (array) call_user_func( $this->service_simulation_runner, $this->simulation_input, $rules );
				return;
			}
			$rules = $this->repository->get_rules_for_target( $this->context()->target_type, $this->context()->target_value );
			if ( array() === $rules && ! $this->context()->is_default() ) {
				$this->errors[] = __( 'Для службы не настроены собственные правила.', 'walls-delivery-calc' );
				return;
			}
			$this->simulation       = $this->simulator->simulate( $rules, $this->simulation_context( $this->simulation_input ) );
			$token = $this->store_simulation_result();
			$this->redirect_with_notice( 'simulated', array( 'simulation_token' => $token ) );
		}
	}

	private function save_rule_action(): void {
		$posted_id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( $posted_id > 0 && ! $this->posted_context_rule() instanceof Rule ) {
			$this->errors[] = __( 'Правило не принадлежит текущему контексту.', 'walls-delivery-calc' );
			return;
		}

		$rule = $this->sanitize_rule_from_post();
		$this->form_rule = $rule;
		$this->errors    = array_merge( $this->localized_errors( $rule->validate() ), $this->validate_admin_conditions( $rule ) );

		if ( array() !== $this->errors ) {
			return;
		}

		$this->repository->save_rule( $rule );
		$this->redirect_with_notice( 'saved' );
	}

	private function delete_rule_action(): void {
		$rule = $this->posted_context_rule();
		if ( $rule instanceof Rule ) {
			$this->repository->delete_rule( (int) $rule->id );
		}

		$this->redirect_with_notice( 'deleted' );
	}

	private function toggle_rule_action(): void {
		$rule = $this->posted_context_rule();
		if ( $rule instanceof Rule ) {
			$data            = $rule->to_array();
			$data['enabled'] = ! $rule->enabled;
			$this->repository->save_rule( Rule::from_array( $data ) );
		}

		$this->redirect_with_notice( 'toggled' );
	}

	private function duplicate_rule_action(): void {
		$rule = $this->posted_context_rule();
		if ( $rule instanceof Rule ) {
			$data               = $rule->to_array();
			$data['id']         = null;
			$data['name']       = sprintf( __( '%s (копия)', 'walls-delivery-calc' ), $rule->name );
			$data['enabled']    = false;
			$data['priority']   = $rule->priority + 1;
			$data['conditions'] = array_map(
				static function ( RuleCondition $condition ): array {
					$item            = $condition->to_array();
					$item['id']      = null;
					$item['rule_id'] = null;
					return $item;
				},
				$rule->conditions
			);
			$this->repository->save_rule( Rule::from_array( $data ) );
		}

		$this->redirect_with_notice( 'duplicated' );
	}

	private function move_rule_action( string $action ): void {
		$rule = $this->posted_context_rule();
		if ( ! $rule instanceof Rule ) {
			$this->redirect_with_notice( 'moved' );
		}

		$rules = $this->repository->get_all_rules_for_target( $this->context()->target_type, $this->context()->target_value );
		foreach ( $rules as $index => $current ) {
			if ( $current->id !== $rule->id ) {
				continue;
			}

			$swap_index = 'move_up' === $action ? $index - 1 : $index + 1;
			if ( ! isset( $rules[ $swap_index ] ) ) {
				break;
			}

			$other      = $rules[ $swap_index ];
			$rule_data  = $rule->to_array();
			$other_data = $other->to_array();

			$rule_data['priority']  = $other->priority;
			$other_data['priority'] = $rule->priority;

			$this->repository->save_rule( Rule::from_array( $rule_data ) );
			$this->repository->save_rule( Rule::from_array( $other_data ) );
			break;
		}

		$this->redirect_with_notice( 'moved' );
	}

	private function posted_context_rule(): ?Rule {
		$id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( $id <= 0 ) {
			return null;
		}

		$rule = $this->repository->get_rule( $id );

		return $this->rule_matches_context( $rule ) ? $rule : null;
	}

	private function sanitize_rule_from_post(): Rule {
		$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : RuleActionTypes::CHANGE_PRICE;
		$operation_type = isset( $_POST['operation_type'] ) ? sanitize_key( wp_unslash( $_POST['operation_type'] ) ) : RuleOperationTypes::EQUALS;
		$operation_base = isset( $_POST['operation_base'] ) ? sanitize_key( wp_unslash( $_POST['operation_base'] ) ) : RuleOperationBases::RUBLES;
		$operation_value = isset( $_POST['operation_value'] ) ? RuleConditionUiSchema::normalize_decimal_input( wp_unslash( $_POST['operation_value'] ) ) : 0.0;
		$operation_text = isset( $_POST['operation_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['operation_text'] ) ) : '';

		if ( RuleActionTypes::CHANGE_DELIVERY_DAYS === $action_type && ! in_array( $operation_base, RuleOperationBases::day_bases(), true ) ) {
			$operation_base = RuleOperationBases::CALENDAR_DAYS;
		}

		if ( RuleActionTypes::CHANGE_DELIVERY_DAYS !== $action_type && in_array( $operation_base, RuleOperationBases::day_bases(), true ) ) {
			$operation_base = RuleOperationBases::RUBLES;
		}

		if ( in_array( $operation_type, array( RuleOperationTypes::MULTIPLY, RuleOperationTypes::DIVIDE ), true ) ) {
			$action_type = RuleActionTypes::CHANGE_PRICE;
			$operation_base = RuleOperationBases::RUBLES;
			$operation_value = max( 0.0001, $operation_value );
		}

		if ( RuleActionTypes::DISABLE_RATE === $action_type ) {
			$operation_type  = RuleOperationTypes::EQUALS;
			$operation_base  = RuleOperationBases::RUBLES;
			$operation_value = 0.0;
		}

		if ( RuleActionTypes::ADD_COMMENT === $action_type ) {
			$operation_type  = RuleOperationTypes::EQUALS;
			$operation_base  = RuleOperationBases::RUBLES;
			$operation_value = 0.0;
		}

		return new Rule(
			isset( $_POST['rule_id'] ) && absint( wp_unslash( $_POST['rule_id'] ) ) > 0 ? absint( wp_unslash( $_POST['rule_id'] ) ) : null,
			isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			isset( $_POST['enabled'] ),
			$this->sort_order_from_post(),
			$this->context()->target_type,
			$this->context()->target_value,
			$action_type,
			$operation_type,
			$operation_value,
			$operation_base,
			isset( $_POST['promo_shipping'] ),
			isset( $_POST['stop_processing'] ),
			$this->sanitize_conditions_from_post(),
			$this->sanitize_group_logic_from_post(),
			$operation_text,
			$this->sanitize_group_expression_from_post()
		);
	}

	/**
	 * @return array<int,RuleCondition>
	 */
	private function sanitize_conditions_from_post(): array {
		$raw = wp_unslash( $_POST['conditions'] ?? array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$conditions = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$condition_type = isset( $item['condition_type'] ) ? sanitize_key( $item['condition_type'] ) : '';
			if ( '' === $condition_type ) {
				continue;
			}

			$condition = $this->condition_schema()->sanitize_condition_input( $item );
			if ( $condition instanceof RuleCondition ) {
				$conditions[] = $condition;
			}
		}

		return $conditions;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_simulation_input(): array {
		$raw = wp_unslash( $_POST['simulation'] ?? array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = $this->default_simulation_input();

		return array(
			'delivery_price' => isset( $raw['delivery_price'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['delivery_price'] ) ) : $defaults['delivery_price'],
			'delivery_days'  => isset( $raw['delivery_days'] ) ? max( 0, (int) sanitize_text_field( (string) $raw['delivery_days'] ) ) : $defaults['delivery_days'],
			'order_total'    => isset( $raw['order_total'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['order_total'] ) ) : $defaults['order_total'],
			'weight'         => isset( $raw['weight'] ) ? max( 0, (int) sanitize_text_field( (string) $raw['weight'] ) ) : $defaults['weight'],
			'country'        => isset( $raw['country'] ) ? sanitize_text_field( (string) $raw['country'] ) : $defaults['country'],
			'postal_code'    => isset( $raw['postal_code'] ) ? sanitize_text_field( (string) $raw['postal_code'] ) : $defaults['postal_code'],
			'city'           => isset( $raw['city'] ) ? sanitize_text_field( (string) $raw['city'] ) : $defaults['city'],
			'location_fias_id' => isset( $raw['location_fias_id'] ) ? sanitize_text_field( (string) $raw['location_fias_id'] ) : $defaults['location_fias_id'],
			'delivery_type'  => in_array( (string) ( $raw['delivery_type'] ?? '' ), array_keys( $this->condition_schema()->delivery_type_options() ), true ) ? sanitize_text_field( (string) $raw['delivery_type'] ) : $defaults['delivery_type'],
			'payment_method' => isset( $raw['payment_method'] ) ? sanitize_text_field( (string) $raw['payment_method'] ) : $defaults['payment_method'],
			'length_cm'      => isset( $raw['length_cm'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['length_cm'] ) ) : $defaults['length_cm'],
			'width_cm'       => isset( $raw['width_cm'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['width_cm'] ) ) : $defaults['width_cm'],
			'height_cm'      => isset( $raw['height_cm'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['height_cm'] ) ) : $defaults['height_cm'],
			'volume_m3'      => isset( $raw['volume_m3'] ) ? max( 0.0, RuleConditionUiSchema::normalize_decimal_input( $raw['volume_m3'] ) ) : $defaults['volume_m3'],
			'date'           => isset( $raw['date'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw['date'] ) ? sanitize_text_field( (string) $raw['date'] ) : $defaults['date'],
		);
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function simulation_context( array $input ): RuleEvaluationContext {
		$order_total = Money::from_rubles( (float) $input['order_total'] );
		$length = (int) round( (float) $input['length_cm'] );
		$width  = (int) round( (float) $input['width_cm'] );
		$height = (int) round( (float) $input['height_cm'] );
		$item   = new PackageItem( 'SIM', 'Simulation item', 1, $order_total, $order_total, (int) $input['weight'], $length, $width, $height );
		$package = Package::from_items( array( $item ), 0, $order_total, $order_total );
		if ( (float) $input['volume_m3'] > 0 ) {
			$package = new Package( array( $item ), $order_total, $order_total, (int) $input['weight'], 0, (int) $input['weight'], $length, $width, $height, (int) round( (float) $input['volume_m3'] * 1000000 ), 'manual' );
		}

		return new RuleEvaluationContext(
			$order_total,
			Money::from_rubles( (float) $input['delivery_price'] ),
			$package,
			new Address( country_code: (string) $input['country'], city: (string) $input['city'], raw_address: (string) $input['city'], fias_id: (string) $input['location_fias_id'] ),
			(string) $input['delivery_type'],
			(string) $input['payment_method'],
			(string) $input['date'],
			array(),
			array( 'original_delivery_days' => (int) $input['delivery_days'], 'selected_location_fias_id' => (string) $input['location_fias_id'] )
		);
	}

	private function should_show_form( ?Rule $edit_rule ): bool {
		return $edit_rule instanceof Rule || isset( $_GET['new_rule'] ) || array() !== $this->errors;
	}

	private function empty_rule(): Rule {
		return new Rule( null, '', true, $this->next_sort_order(), $this->context()->target_type, $this->context()->target_value, RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 0, RuleOperationBases::RUBLES, false, false );
	}

	/**
	 * @return array<int,RuleCondition>
	 */
	private function conditions_by_group_for_form( Rule $rule ): array {
		$groups = array( 1 => array(), 2 => array(), 3 => array() );
		foreach ( $rule->conditions as $condition ) {
			$group = min( 3, max( 1, $condition->condition_group ) );
			$groups[ $group ][] = $condition;
		}

		return $groups;
	}

	private function conditions_summary( Rule $rule ): string {
		if ( array() === $rule->conditions ) {
			return __( 'Нет условий', 'walls-delivery-calc' );
		}

		$expression = sprintf( __( 'Условие применения: %s', 'walls-delivery-calc' ), $this->group_expression_label( $rule->condition_group_expression ) );
		$groups = array();
		foreach ( $rule->conditions as $condition ) {
			$groups[ $condition->condition_group ][] = $this->condition_schema()->condition_summary( $condition );
		}

		$parts = array();
		$logic = Rule::normalized_group_logic( $rule->condition_group_logic );
		$parts[] = $expression;
		foreach ( $groups as $group => $conditions ) {
			$parts[] = sprintf( __( 'Условие %1$d (%2$s): %3$s', 'walls-delivery-calc' ), (int) $group, $this->group_logic_label( $logic[ (int) $group ] ?? 'and' ), implode( '; ', $conditions ) );
		}

		return implode( ' | ', $parts );
	}

	private function operation_summary( Rule $rule ): string {
		if ( RuleActionTypes::DISABLE_RATE === $rule->action_type ) {
			return __( 'Не используется', 'walls-delivery-calc' );
		}

		if ( RuleActionTypes::ADD_COMMENT === $rule->action_type ) {
			return sprintf( __( 'комментарий: "%s"', 'walls-delivery-calc' ), $rule->operation_text );
		}

		$prefix = array(
			RuleOperationTypes::INCREASE => __( 'увеличить на', 'walls-delivery-calc' ),
			RuleOperationTypes::DECREASE => __( 'уменьшить на', 'walls-delivery-calc' ),
			RuleOperationTypes::EQUALS   => __( 'установить', 'walls-delivery-calc' ),
			RuleOperationTypes::MULTIPLY => __( 'умножить на', 'walls-delivery-calc' ),
			RuleOperationTypes::DIVIDE   => __( 'разделить на', 'walls-delivery-calc' ),
		)[ $rule->operation_type ] ?? $this->operation_type_label( $rule->operation_type );

		return trim( $prefix . ' ' . $this->operation_value_label( $rule ) );
	}

	private function action_label( string $value ): string {
		return array(
			RuleActionTypes::CHANGE_PRICE         => __( 'Изменить цену', 'walls-delivery-calc' ),
			RuleActionTypes::CHANGE_DELIVERY_DAYS => __( 'Изменить срок доставки', 'walls-delivery-calc' ),
			RuleActionTypes::ADD_COMMENT          => __( 'Добавить комментарий', 'walls-delivery-calc' ),
			RuleActionTypes::DISABLE_RATE         => __( 'Отключить вариант доставки', 'walls-delivery-calc' ),
		)[ $value ] ?? $value;
	}

	private function operation_type_label( string $value ): string {
		return array(
			RuleOperationTypes::INCREASE => __( 'Увеличить', 'walls-delivery-calc' ),
			RuleOperationTypes::DECREASE => __( 'Уменьшить', 'walls-delivery-calc' ),
			RuleOperationTypes::EQUALS   => __( 'Установить', 'walls-delivery-calc' ),
			RuleOperationTypes::MULTIPLY => __( 'Умножить на', 'walls-delivery-calc' ),
			RuleOperationTypes::DIVIDE   => __( 'Разделить на', 'walls-delivery-calc' ),
		)[ $value ] ?? $value;
	}

	private function render_packaging_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}
		$this->handle_packaging_post();
		$tiers = $this->settings instanceof SettingsRepository ? $this->settings->get_array( PackagingWeightCalculator::SETTINGS_KEY, array() ) : array();
		$tiers = is_array( $tiers ) ? array_values( array_filter( $tiers, 'is_array' ) ) : array();
		?>
		<div class="wrap wdc-rules-admin">
			<h1><?php echo esc_html__( 'Дефолтные правила расчета', 'walls-delivery-calc' ); ?></h1>
			<?php $this->render_full_tabs( 'packaging' ); ?>
			<?php $this->render_notices(); ?>
			<form method="post" class="wdc-packaging-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="wdc_rules_action" value="save_packaging_tiers">
				<table class="widefat striped wdc-packaging-tiers">
					<thead><tr>
						<th><?php echo esc_html__( 'Вес корзины от, г', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Вес корзины до, г', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Вес упаковки, г', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Действия', 'walls-delivery-calc' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( array_merge( $tiers, array( array() ) ) as $index => $tier ) : ?>
							<tr>
								<td><input type="number" min="0" name="packaging_tiers[<?php echo esc_attr( (string) $index ); ?>][cart_weight_from_g]" value="<?php echo esc_attr( (string) ( $tier['cart_weight_from_g'] ?? '' ) ); ?>"></td>
								<td><input type="number" min="0" name="packaging_tiers[<?php echo esc_attr( (string) $index ); ?>][cart_weight_to_g]" value="<?php echo esc_attr( (string) ( $tier['cart_weight_to_g'] ?? '' ) ); ?>"></td>
								<td><input type="number" min="0" name="packaging_tiers[<?php echo esc_attr( (string) $index ); ?>][packaging_weight_g]" value="<?php echo esc_attr( (string) ( $tier['packaging_weight_g'] ?? '' ) ); ?>"></td>
								<td><button class="button" type="button" data-wdc-remove-packaging-row><?php echo esc_html__( 'Удалить строку', 'walls-delivery-calc' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button class="button" type="button" data-wdc-add-packaging-row><?php echo esc_html__( 'Добавить строку', 'walls-delivery-calc' ); ?></button>
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить настройки', 'walls-delivery-calc' ); ?></button>
				</p>
			</form>
			<script>
				document.addEventListener('click', function(event) {
					if (event.target && event.target.matches('[data-wdc-remove-packaging-row]')) {
						event.target.closest('tr').remove();
					}
					if (event.target && event.target.matches('[data-wdc-add-packaging-row]')) {
						var tbody = document.querySelector('.wdc-packaging-tiers tbody');
						var row = tbody ? tbody.querySelector('tr:last-child') : null;
						if (!row || !tbody) { return; }
						var clone = row.cloneNode(true);
						var index = tbody.querySelectorAll('tr').length;
						clone.querySelectorAll('input').forEach(function(input) {
							input.value = '';
							input.name = input.name.replace(/packaging_tiers\[[0-9]+\]/, 'packaging_tiers[' + index + ']');
						});
						tbody.appendChild(clone);
					}
				});
			</script>
		</div>
		<?php
	}

	private function handle_packaging_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			$this->errors[] = __( 'Недостаточно прав или истек срок nonce.', 'walls-delivery-calc' );
			return;
		}
		$action = isset( $_POST['wdc_rules_action'] ) ? sanitize_key( wp_unslash( $_POST['wdc_rules_action'] ) ) : '';
		if ( 'save_packaging_tiers' !== $action || ! $this->settings instanceof SettingsRepository ) {
			return;
		}
		$result = $this->sanitize_packaging_tiers( is_array( $_POST['packaging_tiers'] ?? null ) ? wp_unslash( $_POST['packaging_tiers'] ) : array() );
		if ( array() !== $result['errors'] ) {
			$this->errors = array_merge( $this->errors, $result['errors'] );
			return;
		}
		$this->settings->set( PackagingWeightCalculator::SETTINGS_KEY, $result['tiers'] );
	}

	/**
	 * @param array<int|string,mixed> $rows
	 * @return array{tiers:array<int,array{cart_weight_from_g:int,cart_weight_to_g:int,packaging_weight_g:int}>,errors:array<int,string>}
	 */
	private function sanitize_packaging_tiers( array $rows ): array {
		$tiers = array();
		$errors = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw = array_map( static fn( mixed $value ): string => trim( (string) $value ), $row );
			if ( '' === ( $raw['cart_weight_from_g'] ?? '' ) && '' === ( $raw['cart_weight_to_g'] ?? '' ) && '' === ( $raw['packaging_weight_g'] ?? '' ) ) {
				continue;
			}
			if ( '' === ( $raw['cart_weight_from_g'] ?? '' ) || '' === ( $raw['cart_weight_to_g'] ?? '' ) || '' === ( $raw['packaging_weight_g'] ?? '' ) ) {
				$errors[] = __( 'Все поля строки упаковки обязательны.', 'walls-delivery-calc' );
				continue;
			}
			$tier = array(
				'cart_weight_from_g' => max( 0, (int) $raw['cart_weight_from_g'] ),
				'cart_weight_to_g' => max( 0, (int) $raw['cart_weight_to_g'] ),
				'packaging_weight_g' => max( 0, (int) $raw['packaging_weight_g'] ),
			);
			if ( $tier['cart_weight_to_g'] < $tier['cart_weight_from_g'] ) {
				$errors[] = __( 'Вес корзины до не может быть меньше веса от.', 'walls-delivery-calc' );
				continue;
			}
			$tiers[] = $tier;
		}
		usort( $tiers, static fn( array $a, array $b ): int => $a['cart_weight_from_g'] <=> $b['cart_weight_from_g'] );
		for ( $i = 1; $i < count( $tiers ); ++$i ) {
			if ( $tiers[ $i ]['cart_weight_from_g'] <= $tiers[ $i - 1 ]['cart_weight_to_g'] ) {
				$errors[] = __( 'Диапазоны веса упаковки не должны пересекаться.', 'walls-delivery-calc' );
				break;
			}
		}

		return array( 'tiers' => $tiers, 'errors' => $errors );
	}

	private function render_service_simulation_form(): void {
		$input = $this->simulation_input + array(
			'country' => 'RU',
			'city' => '',
			'location_fias_id' => '',
			'postal_code' => '',
			'weight' => 1000,
			'order_total' => 1000,
			'date' => ( new DateTimeImmutable() )->format( 'Y-m-d' ),
		);
		?>
		<section class="wdc-rules-card" id="wdc-rules-simulation">
			<h2><?php echo esc_html__( 'Проверить правила службы', 'walls-delivery-calc' ); ?></h2>
			<form method="post" class="wdc-simulation-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="wdc_rules_action" value="simulate">
				<div class="wdc-rule-grid">
					<label><span><?php echo esc_html__( 'Почтовый индекс назначения', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="numeric" name="simulation[postal_code]" value="<?php echo esc_attr( (string) $input['postal_code'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Вес товаров, г', 'walls-delivery-calc' ); ?></span><input type="number" min="0" name="simulation[weight]" value="<?php echo esc_attr( (string) $input['weight'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Сумма заказа, руб.', 'walls-delivery-calc' ); ?></span><input type="text" inputmode="decimal" name="simulation[order_total]" value="<?php echo esc_attr( (string) $input['order_total'] ); ?>"></label>
					<label><span><?php echo esc_html__( 'Дата', 'walls-delivery-calc' ); ?></span><input type="date" name="simulation[date]" value="<?php echo esc_attr( (string) $input['date'] ); ?>"></label>
				</div>
				<p class="submit"><button class="button button-primary" type="submit"><?php echo esc_html__( 'Симулировать расчет службы', 'walls-delivery-calc' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function render_service_simulation( array $result ): void {
		?>
		<section class="wdc-rules-result">
			<h2><?php echo esc_html__( 'Результат симуляции службы', 'walls-delivery-calc' ); ?></h2>
			<table class="widefat striped"><tbody>
				<tr><th><?php echo esc_html__( 'API/base price', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['base_price'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Final price after service rules', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['final_price'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Вес товаров, г', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['products_weight_g'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Вес упаковки, г', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['packaging_weight_g'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Итоговый вес для API, г', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['package_weight_with_packaging_g'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Способ учета упаковки', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['packaging_weight_mode'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Source/fallback/cache', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['source'] ?? '-' ) ); ?></td></tr>
				<tr><th><?php echo esc_html__( 'Delivery days', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html( (string) ( $result['delivery_days'] ?? '-' ) ); ?></td></tr>
			</tbody></table>
			<?php if ( ! empty( $result['tariffs'] ) && is_array( $result['tariffs'] ) ) : ?>
				<h3><?php echo esc_html__( 'Активные тарифы', 'walls-delivery-calc' ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Object code', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Тариф', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'API цена', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'API срок', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Итоговая цена', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Итоговый срок', 'walls-delivery-calc' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $result['tariffs'] as $tariff ) : ?>
							<?php if ( ! is_array( $tariff ) ) { continue; } ?>
							<tr>
								<td><?php echo esc_html( (string) ( $tariff['object_code'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $tariff['title'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $tariff['api_price'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $tariff['api_delivery_days'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $tariff['final_price'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $tariff['final_delivery_days'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( ! empty( $result['notice'] ) ) : ?><div class="notice notice-info inline"><p><?php echo esc_html( (string) $result['notice'] ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $result['audit'] ) && is_array( $result['audit'] ) ) : ?>
				<h3><?php echo esc_html__( 'Rules audit', 'walls-delivery-calc' ); ?></h3>
				<pre><?php echo esc_html( (string) wp_json_encode( $result['audit'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			<?php endif; ?>
		</section>
		<?php
	}

	private function context(): RuleAdminContext {
		$this->context ??= RuleAdminContext::default();

		return $this->context;
	}

	private function rule_matches_context( mixed $rule ): bool {
		return $rule instanceof Rule
			&& $rule->target_type === $this->context()->target_type
			&& $rule->target_value === $this->context()->target_value;
	}

	private function operation_base_label( string $value ): string {
		return array(
			RuleOperationBases::RUBLES                         => __( 'руб.', 'walls-delivery-calc' ),
			RuleOperationBases::PERCENT_OF_DELIVERY            => __( '% от доставки', 'walls-delivery-calc' ),
			RuleOperationBases::PERCENT_OF_ORDER               => __( '% от заказа', 'walls-delivery-calc' ),
			RuleOperationBases::PERCENT_OF_ORDER_AND_DELIVERY  => __( '% от заказа и доставки', 'walls-delivery-calc' ),
			RuleOperationBases::CALENDAR_DAYS                  => __( 'календарных дня', 'walls-delivery-calc' ),
			RuleOperationBases::BUSINESS_DAYS                  => __( 'рабочих дня', 'walls-delivery-calc' ),
		)[ $value ] ?? $value;
	}

	private function operation_value_label( Rule $rule ): string {
		$value = $this->format_decimal( $rule->operation_value );
		$base  = $this->operation_base_label( $rule->operation_base );

		if ( in_array( $rule->operation_type, array( RuleOperationTypes::MULTIPLY, RuleOperationTypes::DIVIDE ), true ) ) {
			return $value;
		}

		if ( in_array( $rule->operation_base, array( RuleOperationBases::PERCENT_OF_DELIVERY, RuleOperationBases::PERCENT_OF_ORDER, RuleOperationBases::PERCENT_OF_ORDER_AND_DELIVERY ), true ) ) {
			return $value . $base;
		}

		return trim( $value . ' ' . $base );
	}

	private function format_decimal( float $value ): string {
		$formatted = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );

		return '' === $formatted ? '0' : $formatted;
	}

	private function condition_type_label( string $value ): string {
		return array(
			RuleConditionTypes::ORDER_TOTAL    => __( 'сумма заказа', 'walls-delivery-calc' ),
			RuleConditionTypes::ITEMS_COUNT    => __( 'количество товаров', 'walls-delivery-calc' ),
			RuleConditionTypes::PAYMENT_METHOD => __( 'способ оплаты', 'walls-delivery-calc' ),
			RuleConditionTypes::CITY           => __( 'город', 'walls-delivery-calc' ),
			RuleConditionTypes::COUNTRY        => __( 'страна', 'walls-delivery-calc' ),
			RuleConditionTypes::DELIVERY_TYPE  => __( 'тип доставки', 'walls-delivery-calc' ),
			RuleConditionTypes::DELIVERY_PRICE => __( 'цена доставки', 'walls-delivery-calc' ),
			RuleConditionTypes::WEIGHT         => __( 'вес', 'walls-delivery-calc' ),
			RuleConditionTypes::DIMENSIONS     => __( 'габариты (Д*Ш*В см)', 'walls-delivery-calc' ),
			RuleConditionTypes::VOLUME         => __( 'объем', 'walls-delivery-calc' ),
			RuleConditionTypes::DAY_OF_WEEK    => __( 'день недели', 'walls-delivery-calc' ),
			RuleConditionTypes::DAY_OF_MONTH   => __( 'день месяца', 'walls-delivery-calc' ),
			RuleConditionTypes::MONTH          => __( 'месяц', 'walls-delivery-calc' ),
			RuleConditionTypes::DATE           => __( 'дата', 'walls-delivery-calc' ),
		)[ $value ] ?? $value;
	}

	private function operator_label( string $value ): string {
		return array(
			RuleOperators::EQ           => '=',
			RuleOperators::NEQ          => '!=',
			RuleOperators::GT           => '>',
			RuleOperators::GTE          => '>=',
			RuleOperators::LT           => '<',
			RuleOperators::LTE          => '<=',
			RuleOperators::IN           => 'in',
			RuleOperators::NOT_IN       => 'not in',
			RuleOperators::CONTAINS     => 'contains',
			RuleOperators::NOT_CONTAINS => 'not contains',
		)[ $value ] ?? $value;
	}

	/**
	 * @param array<int,string> $errors
	 * @return array<int,string>
	 */
	private function localized_errors( array $errors ): array {
		$map = array(
			'name is required'                         => __( 'Название обязательно.', 'walls-delivery-calc' ),
			'action_type is invalid'                  => __( 'Некорректный тип действия.', 'walls-delivery-calc' ),
			'operation_type is invalid'               => __( 'Некорректная операция.', 'walls-delivery-calc' ),
			'operation_base is invalid'               => __( 'Некорректная база операции.', 'walls-delivery-calc' ),
			'operation_value must be greater than 0'  => __( 'Значение операции должно быть больше нуля.', 'walls-delivery-calc' ),
			'operation_text is required'              => __( 'Комментарий обязателен для действия "Добавить комментарий".', 'walls-delivery-calc' ),
			'condition_group must be greater than 0'  => __( 'Группа условия должна быть больше 0.', 'walls-delivery-calc' ),
			'condition_type is invalid'               => __( 'Некорректный тип условия.', 'walls-delivery-calc' ),
			'operator is invalid'                     => __( 'Некорректный оператор условия.', 'walls-delivery-calc' ),
			'condition_group_logic is invalid'        => __( 'Некорректная логика группы условий.', 'walls-delivery-calc' ),
			'condition_group_expression is invalid'   => __( 'Некорректное условие применения.', 'walls-delivery-calc' ),
		);

		return array_map( static fn ( string $error ): string => $map[ $error ] ?? $error, $errors );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function default_simulation_input(): array {
		return array(
			'delivery_price' => 450,
			'delivery_days'  => 5,
			'order_total'    => 1000,
			'weight'         => 12000,
			'country'        => 'RU',
			'postal_code'    => '',
			'city'           => 'Moscow',
			'location_fias_id' => '',
			'delivery_type'  => 'courier',
			'payment_method' => $this->default_payment_method(),
			'length_cm'      => 10,
			'width_cm'       => 10,
			'height_cm'      => 10,
			'volume_m3'      => 0.001,
			'date'           => ( new DateTimeImmutable() )->format( 'Y-m-d' ),
		);
	}

	private function load_simulation_from_request(): void {
		$token = isset( $_GET['simulation_token'] ) ? sanitize_key( wp_unslash( $_GET['simulation_token'] ) ) : '';
		if ( '' === $token ) {
			return;
		}

		$data = get_transient( $this->simulation_transient_key( $token ) );
		if ( is_array( $data ) ) {
			$this->simulation_input = is_array( $data['input'] ?? null ) ? $data['input'] : array();
			$this->simulation       = ( $data['result'] ?? null ) instanceof RuleEngineResult ? $data['result'] : null;
		}

		delete_transient( $this->simulation_transient_key( $token ) );
	}

	private function store_simulation_result(): string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'simulation_', true );
		set_transient(
			$this->simulation_transient_key( $token ),
			array(
				'input'  => $this->simulation_input,
				'result' => $this->simulation,
			),
			10 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	private function simulation_transient_key( string $token ): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return 'wdc_rules_sim_' . $user_id . '_' . sanitize_key( $token );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function redirect_with_notice( string $notice, array $args = array() ): void {
		wp_safe_redirect( $this->page_url( array_merge( array( 'wdc_rules_notice' => $notice ), $args ) ) );
		exit;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function page_url( array $args = array() ): string {
		if ( ! $this->context()->is_default() ) {
			$separator = str_contains( $this->context()->return_url, '?' ) ? '&' : '?';

			return $this->context()->return_url . ( array() === $args ? '' : $separator . http_build_query( $args ) );
		}

		$query = array_merge( array( 'page' => $this->context()->page_slug ), $args );

		return admin_url( 'admin.php?' . http_build_query( $query ) );
	}

	private function money_label( mixed $money ): string {
		if ( ! is_object( $money ) || ! method_exists( $money, 'get_rubles' ) ) {
			return '-';
		}

		return number_format( (float) $money->get_rubles(), 2, '.', ' ' ) . ' ' . $money->get_currency();
	}

	private function reorder_rules_action(): void {
		$raw = isset( $_POST['ordered_rule_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ordered_rule_ids'] ) ) : '';
		$ids = array_values(
			array_filter(
				array_map( 'absint', explode( ',', $raw ) ),
				static fn ( int $id ): bool => $id > 0
			)
		);

		if ( array() !== $ids ) {
			$this->repository->reorder_rules_for_target( $this->context()->target_type, $this->context()->target_value, $ids );
		}

		$this->redirect_with_notice( 'moved' );
	}

	private function sort_order_from_post(): int {
		$id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( $id > 0 ) {
			$rule = $this->repository->get_rule( $id );
			if ( $rule instanceof Rule ) {
				return $rule->priority;
			}
		}

		return $this->next_sort_order();
	}

	private function next_sort_order(): int {
		$rules = $this->repository->get_all_rules_for_target( $this->context()->target_type, $this->context()->target_value );
		$last  = 0;
		foreach ( $rules as $rule ) {
			$last = max( $last, $rule->priority );
		}

		return $last + 10;
	}

	private function date_range_label( DateRange $range ): string {
		$unit = DateRange::UNIT_BUSINESS_DAYS === $range->unit || DateRange::UNIT_WORKING_DAYS === $range->unit
			? __( 'раб. дн.', 'walls-delivery-calc' )
			: __( 'к. дн.', 'walls-delivery-calc' );

		if ( null !== $range->min_days && null !== $range->max_days && $range->min_days !== $range->max_days ) {
			return sprintf( '%d-%d %s', $range->min_days, $range->max_days, $unit );
		}

		$days = $range->min_days ?? $range->max_days ?? 0;

		return sprintf( '%d %s', $days, $unit );
	}

	private function render_group_logic_fields( Rule $rule ): void {
		$logic = Rule::normalized_group_logic( $rule->condition_group_logic );
		?>
		<div class="wdc-condition-group-logic">
			<label class="wdc-condition-expression">
				<span><?php echo esc_html__( 'Условие применения', 'walls-delivery-calc' ); ?></span>
				<select name="condition_group_expression" data-group-expression>
					<?php foreach ( $this->group_expression_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( Rule::normalized_group_expression( $rule->condition_group_expression ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<strong><?php echo esc_html__( 'Логика условий внутри групп', 'walls-delivery-calc' ); ?></strong>
			<?php for ( $group = 1; $group <= 3; ++$group ) : ?>
				<label>
					<span><?php echo esc_html( sprintf( __( 'Условие %d', 'walls-delivery-calc' ), $group ) ); ?></span>
					<select name="condition_group_logic[<?php echo esc_attr( (string) $group ); ?>]" data-group-logic>
						<option value="and" <?php selected( $logic[ $group ], 'and' ); ?>><?php echo esc_html__( 'И', 'walls-delivery-calc' ); ?></option>
						<option value="or" <?php selected( $logic[ $group ], 'or' ); ?>><?php echo esc_html__( 'ИЛИ', 'walls-delivery-calc' ); ?></option>
					</select>
				</label>
			<?php endfor; ?>
		</div>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function sanitize_group_logic_from_post(): array {
		$raw = wp_unslash( $_POST['condition_group_logic'] ?? array() );
		$raw = is_array( $raw ) ? $raw : array();

		$logic = array();
		for ( $group = 1; $group <= 3; ++$group ) {
			$value = isset( $raw[ $group ] ) ? sanitize_key( (string) $raw[ $group ] ) : 'and';
			$logic[ $group ] = 'or' === $value ? 'or' : 'and';
		}

		return $logic;
	}

	private function sanitize_group_expression_from_post(): string {
		$value = isset( $_POST['condition_group_expression'] ) ? sanitize_key( wp_unslash( $_POST['condition_group_expression'] ) ) : Rule::DEFAULT_GROUP_EXPRESSION;

		return Rule::normalized_group_expression( $value );
	}

	/**
	 * @return array<string,string>
	 */
	private function group_expression_options(): array {
		return array(
			'condition_1'              => __( 'Условие 1', 'walls-delivery-calc' ),
			'condition_2'              => __( 'Условие 2', 'walls-delivery-calc' ),
			'condition_3'              => __( 'Условие 3', 'walls-delivery-calc' ),
			'condition_1_or_2'         => __( 'Условие 1 ИЛИ Условие 2', 'walls-delivery-calc' ),
			'condition_1_and_2'        => __( 'Условие 1 И Условие 2', 'walls-delivery-calc' ),
			'condition_1_or_3'         => __( 'Условие 1 ИЛИ Условие 3', 'walls-delivery-calc' ),
			'condition_1_and_3'        => __( 'Условие 1 И Условие 3', 'walls-delivery-calc' ),
			'condition_2_or_3'         => __( 'Условие 2 ИЛИ Условие 3', 'walls-delivery-calc' ),
			'condition_2_and_3'        => __( 'Условие 2 И Условие 3', 'walls-delivery-calc' ),
			'condition_1_or_2_or_3'    => __( 'Условие 1 ИЛИ Условие 2 ИЛИ Условие 3', 'walls-delivery-calc' ),
			'condition_1_and_2_and_3'  => __( 'Условие 1 И Условие 2 И Условие 3', 'walls-delivery-calc' ),
			'condition_1_and_2_or_3'   => __( '(Условие 1 И Условие 2) ИЛИ Условие 3', 'walls-delivery-calc' ),
			'condition_1_or_2_and_3'   => __( 'Условие 1 ИЛИ (Условие 2 И Условие 3)', 'walls-delivery-calc' ),
		);
	}

	private function group_expression_label( string $expression ): string {
		$options = $this->group_expression_options();

		return $options[ Rule::normalized_group_expression( $expression ) ] ?? $options[ Rule::DEFAULT_GROUP_EXPRESSION ];
	}

	/**
	 * @return array<int,string>
	 */
	private function validate_admin_conditions( Rule $rule ): array {
		$errors = array();
		foreach ( $rule->conditions as $condition ) {
			if ( RuleConditionTypes::CITY !== $condition->condition_type ) {
				continue;
			}

			if ( '' === trim( $condition->value_text ) || ! $this->location_fias_id_exists( $condition->value_text ) ) {
				$errors[] = __( 'Для условия Населенный пункт нужно указать существующий FIAS ID из локальной базы населенных пунктов.', 'walls-delivery-calc' );
			}
		}

		return $errors;
	}

	private function location_fias_id_exists( string $fias_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wdc_locations';
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT fias_id FROM {$table} WHERE active = 1 AND fias_id = %s LIMIT 1",
				$fias_id
			)
		);

		return ! in_array( $result, array( null, '', 0, '0' ), true );
	}

	private function group_logic_label( string $logic ): string {
		return 'or' === $logic ? __( 'ИЛИ', 'walls-delivery-calc' ) : __( 'И', 'walls-delivery-calc' );
	}

	private function condition_schema(): RuleConditionUiSchema {
		static $schema = null;
		if ( ! $schema instanceof RuleConditionUiSchema ) {
			$schema = new RuleConditionUiSchema();
		}

		return $schema;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function js_config(): array {
		$schema = $this->condition_schema();

		return array(
			'conditions' => $schema->definitions( $this->payment_method_options(), $this->country_options() ),
			'operatorLabels' => $schema->operator_labels(),
			'locationSearch' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => 'wdc_rules_location_search',
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
			'strings' => array(
				'selectValue' => __( 'Выберите значение', 'walls-delivery-calc' ),
				'searchLocation' => __( 'Введите FIAS ID населенного пункта', 'walls-delivery-calc' ),
				'noResults' => __( 'FIAS ID не найден в локальной базе населенных пунктов', 'walls-delivery-calc' ),
			),
		);
	}

	private function condition_value_payload( RuleCondition $condition ): string {
		$payload = wp_json_encode(
			array(
				'value_text'   => $condition->value_text,
				'value_number' => $condition->value_number,
				'value_json'   => $condition->value_json,
			)
		);

		return false === $payload ? '{}' : $payload;
	}

	/**
	 * @param array<string,string|int> $options
	 */
	private function render_select( string $name, array $options, string $selected ): void {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $selected, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private function payment_method_options(): array {
		$options = array();
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->payment_gateways ) && is_object( WC()->payment_gateways ) && method_exists( WC()->payment_gateways, 'payment_gateways' ) ) {
			$gateways = WC()->payment_gateways->payment_gateways();
			if ( is_array( $gateways ) ) {
				foreach ( $gateways as $id => $gateway ) {
					$title = is_object( $gateway ) && isset( $gateway->title ) ? (string) $gateway->title : (string) $id;
					$options[ (string) $id ] = '' !== trim( $title ) ? $title : (string) $id;
				}
			}
		}

		return array() !== $options ? $options : array( 'cod' => __( 'Оплата при получении', 'walls-delivery-calc' ) );
	}

	private function default_payment_method(): string {
		$options = $this->payment_method_options();

		return (string) array_key_first( $options );
	}

	/**
	 * @return array<string,string>
	 */
	private function country_options(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_countries' ) ) {
			$countries = WC()->countries->get_countries();
			if ( is_array( $countries ) && array() !== $countries ) {
				return array_map( 'strval', $countries );
			}
		}

		return array( 'RU' => __( 'Россия', 'walls-delivery-calc' ) );
	}

	public function ajax_location_search(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) ), 403 );
		}

		global $wpdb;

		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['query'] ) ) : '';
		$table = $wpdb->prefix . 'wdc_locations';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fias_id, display_name FROM {$table} WHERE active = 1 AND fias_id = %s LIMIT 1",
				$query
			),
			ARRAY_A
		);

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$fias_id = (string) ( $row['fias_id'] ?? '' );
			if ( '' === $fias_id ) {
				continue;
			}
			$display = (string) ( $row['display_name'] ?? $fias_id );
			$items[] = array(
				'fias_id'      => $fias_id,
				'display_name' => $display,
				'label'        => $display . ' (' . $fias_id . ')',
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}
}
