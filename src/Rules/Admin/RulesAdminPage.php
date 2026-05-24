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

	public function __construct(
		private PluginEnvironment $environment,
		private RuleRepository $repository,
		private RuleSimulator $simulator
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
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-rules-admin', $this->environment->plugin_url() . 'assets/admin/rules-admin.css', array(), $this->environment->version() );
		wp_enqueue_script( 'wdc-rules-admin', $this->environment->plugin_url() . 'assets/admin/rules-admin.js', array(), $this->environment->version(), true );
		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script( 'wdc-rules-admin', 'wdcRulesAdmin', $this->js_config() );
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$this->handle_post();
		$this->load_simulation_from_request();

		$rules     = $this->repository->get_all_default_rules();
		$edit_rule = $this->form_rule;
		if ( null === $edit_rule ) {
			$edit_id = isset( $_GET['edit_rule'] ) ? absint( wp_unslash( $_GET['edit_rule'] ) ) : 0;
			if ( $edit_id > 0 ) {
				$loaded = $this->repository->get_rule( $edit_id );
				if ( $loaded instanceof Rule && RuleRepository::TARGET_DEFAULT === $loaded->target_type ) {
					$edit_rule = $loaded;
				}
			}
		}

		?>
		<div class="wrap wdc-rules-admin">
			<h1><?php echo esc_html__( 'Правила расчета', 'walls-delivery-calc' ); ?></h1>
			<p class="description"><?php echo esc_html__( 'Эти правила применяются по умолчанию для транспортных компаний, у которых не настроены собственные правила.', 'walls-delivery-calc' ); ?></p>

			<?php $this->render_notices(); ?>

			<div class="wdc-rules-toolbar">
				<a class="button button-primary" href="<?php echo esc_url( $this->page_url( array( 'new_rule' => 1 ) ) ); ?>"><?php echo esc_html__( 'Добавить правило', 'walls-delivery-calc' ); ?></a>
				<a class="button" href="#wdc-rules-simulation"><?php echo esc_html__( 'Проверить правила', 'walls-delivery-calc' ); ?></a>
			</div>

			<section class="wdc-rules-scope">
				<strong><?php echo esc_html__( 'Дефолтные правила', 'walls-delivery-calc' ); ?></strong>
				<span><?php echo esc_html__( 'target_type=default, target_value пустой. Условия внутри группы работают как AND, разные группы как OR.', 'walls-delivery-calc' ); ?></span>
			</section>

			<?php $this->render_rules_table( $rules ); ?>

			<?php if ( $this->should_show_form( $edit_rule ) ) : ?>
				<?php $this->render_rule_form( $edit_rule ?? $this->empty_rule() ); ?>
			<?php endif; ?>

			<?php $this->render_simulation_form(); ?>

			<?php if ( $this->simulation instanceof RuleEngineResult ) : ?>
				<?php $this->render_simulation( $this->simulation ); ?>
			<?php endif; ?>
		</div>
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
					<tr><td colspan="9"><?php echo esc_html__( 'Дефолтные правила пока не созданы.', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $index => $rule ) : ?>
					<tr draggable="true" data-rule-row data-rule-id="<?php echo esc_attr( (string) $rule->id ); ?>">
						<td><?php echo esc_html( $rule->enabled ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
						<td><span class="wdc-drag-handle" aria-hidden="true">↕</span><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
						<td>
							<strong><?php echo esc_html( $rule->name ); ?></strong>
							<small><?php echo esc_html__( 'Дефолтные правила', 'walls-delivery-calc' ); ?></small>
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
				<div class="wdc-conditions" data-conditions>
					<?php foreach ( $this->conditions_for_form( $rule ) as $index => $condition ) : ?>
						<?php $this->render_condition_row( $condition, $index ); ?>
					<?php endforeach; ?>
				</div>
				<button class="button" type="button" data-add-condition><?php echo esc_html__( 'Добавить условие', 'walls-delivery-calc' ); ?></button>

				<p class="submit">
					<button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить правило', 'walls-delivery-calc' ); ?></button>
					<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php echo esc_html__( 'Отмена', 'walls-delivery-calc' ); ?></a>
				</p>
			</form>
		</section>
		<?php
	}

	private function render_condition_row( RuleCondition $condition, int $index ): void {
		$value_json = wp_json_encode( $condition->value_json );
		?>
		<div class="wdc-condition-row" data-condition-row data-condition-value="<?php echo esc_attr( $this->condition_value_payload( $condition ) ); ?>">
			<label>
				<span><?php echo esc_html__( 'Условие', 'walls-delivery-calc' ); ?></span>
				<select name="conditions[<?php echo esc_attr( (string) $index ); ?>][condition_group]" data-condition-group>
					<?php for ( $group = 1; $group <= 3; ++$group ) : ?>
						<option value="<?php echo esc_attr( (string) $group ); ?>" <?php selected( min( 3, max( 1, $condition->condition_group ) ), $group ); ?>><?php echo esc_html( sprintf( __( 'Условие %d', 'walls-delivery-calc' ), $group ) ); ?></option>
					<?php endfor; ?>
				</select>
			</label>
			<label>
				<span><?php echo esc_html__( 'Тип условия', 'walls-delivery-calc' ); ?></span>
				<select name="conditions[<?php echo esc_attr( (string) $index ); ?>][condition_type]">
					<option value=""><?php echo esc_html__( 'Не выбрано', 'walls-delivery-calc' ); ?></option>
					<?php foreach ( RuleConditionTypes::all() as $value ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $condition->condition_type, $value ); ?>><?php echo esc_html( $this->condition_type_label( $value ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php echo esc_html__( 'Оператор', 'walls-delivery-calc' ); ?></span>
				<select name="conditions[<?php echo esc_attr( (string) $index ); ?>][operator]" data-condition-operator data-selected-operator="<?php echo esc_attr( $condition->operator ); ?>"></select>
			</label>
			<div class="wdc-condition-value" data-condition-value-control></div>
			<input type="hidden" name="conditions[<?php echo esc_attr( (string) $index ); ?>][value_text]" value="<?php echo esc_attr( $condition->value_text ); ?>" data-value-text>
			<input type="hidden" name="conditions[<?php echo esc_attr( (string) $index ); ?>][value_number]" value="<?php echo esc_attr( null === $condition->value_number ? '' : (string) $condition->value_number ); ?>" data-value-number>
			<input type="hidden" name="conditions[<?php echo esc_attr( (string) $index ); ?>][value_json]" value="<?php echo esc_attr( false === $value_json ? '{}' : $value_json ); ?>" data-value-json>
			<button class="button" type="button" data-remove-condition><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
		</div>
		<?php
	}

	private function render_simulation_form(): void {
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
			$this->simulation       = $this->simulator->simulate( $this->repository->get_default_rules(), $this->simulation_context( $this->simulation_input ) );
			$token = $this->store_simulation_result();
			$this->redirect_with_notice( 'simulated', array( 'simulation_token' => $token ) );
		}
	}

	private function save_rule_action(): void {
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
		$rule = $this->posted_default_rule();
		if ( $rule instanceof Rule ) {
			$this->repository->delete_rule( (int) $rule->id );
		}

		$this->redirect_with_notice( 'deleted' );
	}

	private function toggle_rule_action(): void {
		$rule = $this->posted_default_rule();
		if ( $rule instanceof Rule ) {
			$data            = $rule->to_array();
			$data['enabled'] = ! $rule->enabled;
			$this->repository->save_rule( Rule::from_array( $data ) );
		}

		$this->redirect_with_notice( 'toggled' );
	}

	private function duplicate_rule_action(): void {
		$rule = $this->posted_default_rule();
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
		$rule = $this->posted_default_rule();
		if ( ! $rule instanceof Rule ) {
			$this->redirect_with_notice( 'moved' );
		}

		$rules = $this->repository->get_all_default_rules();
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

	private function posted_default_rule(): ?Rule {
		$id = isset( $_POST['rule_id'] ) ? absint( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( $id <= 0 ) {
			return null;
		}

		$rule = $this->repository->get_rule( $id );

		return $rule instanceof Rule && RuleRepository::TARGET_DEFAULT === $rule->target_type ? $rule : null;
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
			RuleRepository::TARGET_DEFAULT,
			'',
			$action_type,
			$operation_type,
			$operation_value,
			$operation_base,
			isset( $_POST['promo_shipping'] ),
			isset( $_POST['stop_processing'] ),
			$this->sanitize_conditions_from_post(),
			$this->sanitize_group_logic_from_post(),
			$operation_text
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
		return new Rule( null, '', true, $this->next_sort_order(), RuleRepository::TARGET_DEFAULT, '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 0, RuleOperationBases::RUBLES, false, false );
	}

	/**
	 * @return array<int,RuleCondition>
	 */
	private function conditions_for_form( Rule $rule ): array {
		return array() !== $rule->conditions ? $rule->conditions : array( new RuleCondition( null, null, 1, '', '', '', null, array() ) );
	}

	private function conditions_summary( Rule $rule ): string {
		if ( array() === $rule->conditions ) {
			return __( 'Без условий', 'walls-delivery-calc' );
		}

		$groups = array();
		foreach ( $rule->conditions as $condition ) {
			$groups[ $condition->condition_group ][] = $this->condition_schema()->condition_summary( $condition );
		}

		$parts = array();
		$logic = Rule::normalized_group_logic( $rule->condition_group_logic );
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
		)[ $value ] ?? $value;
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
			'operation_text is required'              => __( 'Комментарий обязателен для действия "Добавить комментарий".', 'walls-delivery-calc' ),
			'condition_group must be greater than 0'  => __( 'Группа условия должна быть больше 0.', 'walls-delivery-calc' ),
			'condition_type is invalid'               => __( 'Некорректный тип условия.', 'walls-delivery-calc' ),
			'operator is invalid'                     => __( 'Некорректный оператор условия.', 'walls-delivery-calc' ),
			'condition_group_logic is invalid'        => __( 'Некорректная логика группы условий.', 'walls-delivery-calc' ),
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
		$query = array_merge( array( 'page' => self::PAGE_SLUG ), $args );

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
			$this->repository->reorder_default_rules( $ids );
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
		$rules = $this->repository->get_all_default_rules();
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
			<strong><?php echo esc_html__( 'Логика групп условий', 'walls-delivery-calc' ); ?></strong>
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
