<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Rules\Admin\RuleAdminContext;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class DeliveryServicesAdminPage {
	public const MENU_SLUG = 'wdc-delivery-services';

	public function __construct(
		private DeliveryServiceRepository $services,
		private DeliveryServiceCountryRepository $countries,
		private RulesAdminPage $rules_admin,
		private RuleRepository $rules
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Службы доставки', 'walls-delivery-calc' ),
			__( 'Службы доставки', 'walls-delivery-calc' ),
			AdminMenu::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( AdminMenu::CAPABILITY ) || ( $_POST['wdc_delivery_services_action'] ?? '' ) === '' ) {
			return;
		}

		check_admin_referer( 'wdc_delivery_services' );
		$action = sanitize_key( wp_unslash( $_POST['wdc_delivery_services_action'] ) );
		if ( 'save' === $action ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$data = $this->sanitize_service_data();
			if ( $id > 0 ) {
				$this->services->update_service( $id, $data );
			} else {
				$id = $this->services->create_service( $data );
			}
			$this->countries->replace_countries( $id, $this->countries_from_post() );
		}

		if ( 'toggle' === $action ) {
			$this->services->update_service( (int) $_POST['id'], array( 'enabled' => empty( $_POST['enabled'] ) ? 1 : 0 ) );
		}

		if ( 'delete' === $action ) {
			$this->services->soft_delete_service( (int) $_POST['id'] );
		}

		if ( 'reorder' === $action ) {
			$this->services->reorder( array_map( 'intval', explode( ',', (string) wp_unslash( $_POST['ordered_ids'] ?? '' ) ) ) );
		}

		if ( 'copy_default_rules' === $action ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$service = $this->services->find_by_service_key( $service_key );
			if ( $service instanceof DeliveryService ) {
				$this->copy_default_rules_to_service( $service );
				wp_safe_redirect( $this->service_rules_url( $service, array( 'wdc_rules_notice' => 'copied' ) ) );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$service_key = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		$service = '' !== $service_key ? $this->services->find_by_service_key( $service_key ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Службы доставки', 'walls-delivery-calc' ); ?></h1>
			<?php if ( $service instanceof DeliveryService ) : ?>
				<?php $this->render_edit_page( $service ); ?>
			<?php else : ?>
				<?php $this->render_table(); ?>
				<?php $this->render_create_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_table(): void {
		$services = $this->services->list_active();
		?>
		<form method="post" style="margin: 16px 0;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="reorder">
			<input type="hidden" name="ordered_ids" value="<?php echo esc_attr( implode( ',', array_map( static fn ( DeliveryService $service ): int => (int) $service->id, $services ) ) ); ?>">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Статус', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Название', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Тип', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Carrier', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Availability', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Countries', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Rules', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Округление', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Minimum', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Sort', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Действия', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $services as $service ) : ?>
						<tr>
							<td><?php echo esc_html( $service->enabled ? __( 'включена', 'walls-delivery-calc' ) : __( 'выключена', 'walls-delivery-calc' ) ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&service=' . rawurlencode( $service->service_key ) ) ); ?>"><?php echo esc_html( $service->title ); ?></a></td>
							<td><?php echo esc_html( $service->service_type ); ?></td>
							<td><?php echo esc_html( $service->carrier_key ); ?></td>
							<td><?php echo esc_html( $service->availability_mode ); ?></td>
							<td><?php echo esc_html( $this->countries_summary( $service ) ); ?></td>
							<td><?php echo esc_html( $service->use_default_rules_when_no_service_rules ? __( 'own/default', 'walls-delivery-calc' ) : __( 'own only', 'walls-delivery-calc' ) ); ?></td>
							<td><?php echo esc_html( $service->round_up_to_ruble ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ); ?></td>
							<td><?php echo esc_html( (string) $service->minimum_price_rub ); ?></td>
							<td><?php echo esc_html( (string) $service->sort_order ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
									<input type="hidden" name="wdc_delivery_services_action" value="toggle">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
									<input type="hidden" name="enabled" value="<?php echo esc_attr( $service->enabled ? '1' : '0' ); ?>">
									<button class="button"><?php echo esc_html( $service->enabled ? __( 'Выключить', 'walls-delivery-calc' ) : __( 'Включить', 'walls-delivery-calc' ) ); ?></button>
								</form>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
									<input type="hidden" name="wdc_delivery_services_action" value="delete">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
									<button class="button button-link-delete"><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
	}

	private function render_edit_page( DeliveryService $service ): void {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'main';
		$tabs = array(
			'main' => 'Основное',
			'availability' => 'Доступность',
			'calculation' => 'Расчет',
			'rules' => 'Правила',
		);
		?>
		<h2><?php echo esc_html( $service->title ); ?></h2>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_key => $tab ) : ?>
				<a class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&service=' . rawurlencode( $service->service_key ) . '&tab=' . rawurlencode( $tab_key ) ) ); ?>"><?php echo esc_html( $tab ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( 'rules' === $current_tab ) : ?>
			<?php $this->render_rules_tab( $service ); ?>
		<?php else : ?>
			<?php $this->render_service_form( $service ); ?>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Назад к списку', 'walls-delivery-calc' ); ?></a></p>
		<?php
	}

	private function render_rules_tab( DeliveryService $service ): void {
		$service_rules = $this->rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, $service->service_key );
		if ( array() === $service_rules ) {
			?>
			<div class="notice notice-info inline"><p><?php echo esc_html__( 'Для этой службы не настроены собственные правила. При расчете будут применяться дефолтные правила, если включена соответствующая настройка службы.', 'walls-delivery-calc' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wdc-rules' ) ); ?>"><?php echo esc_html__( 'Открыть дефолтные правила', 'walls-delivery-calc' ); ?></a></p>
			<?php
		}
		?>
		<form method="post" style="margin: 12px 0;" onsubmit="<?php echo array() !== $service_rules ? esc_attr( "return confirm('У службы уже есть собственные правила. Скопировать дефолтные правила дополнительно?');" ) : ''; ?>">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="copy_default_rules">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<button class="button"><?php echo esc_html__( 'Скопировать дефолтные правила', 'walls-delivery-calc' ); ?></button>
		</form>
		<?php
		$this->rules_admin->render_for_context(
			new RuleAdminContext(
				RuleRepository::TARGET_SERVICE,
				$service->service_key,
				self::MENU_SLUG,
				$this->service_rules_url( $service ),
				'Правила службы: ' . $service->title,
				'Правило службы',
				'Для этой службы не настроены собственные правила. При расчете будут применяться дефолтные правила, если включена соответствующая настройка службы.',
				true
			)
		);
	}

	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Новая служба', 'walls-delivery-calc' ) . '</h2>';
		$this->render_service_form( null );
	}

	private function render_service_form( ?DeliveryService $service ): void {
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $service->id ?? 0 ) ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ?? '' ); ?>
				<?php $this->text_row( 'title', __( 'Название', 'walls-delivery-calc' ), $service->title ?? '' ); ?>
				<?php $this->text_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ?? '' ); ?>
				<?php $this->select_row( 'service_type', __( 'Тип', 'walls-delivery-calc' ), $service->service_type ?? DeliveryService::TYPE_FIXED, array( DeliveryService::TYPE_API, DeliveryService::TYPE_FIXED, DeliveryService::TYPE_WEIGHT_BASED ) ); ?>
				<?php $this->select_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, array( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY, DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, DeliveryService::AVAILABILITY_ALL_COUNTRIES, DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ) ); ?>
				<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), $service instanceof DeliveryService ? implode( ',', $this->countries->countries( (int) $service->id ) ) : '' ); ?>
				<?php $this->text_row( 'minimum_price_rub', __( 'Minimum price RUB', 'walls-delivery-calc' ), (string) ( $service->minimum_price_rub ?? 1 ) ); ?>
				<?php $this->text_row( 'sort_order', __( 'Sort order', 'walls-delivery-calc' ), (string) ( $service->sort_order ?? 100 ) ); ?>
				<?php $this->checkbox_row( 'enabled', __( 'Включена', 'walls-delivery-calc' ), $service->enabled ?? true ); ?>
				<?php $this->checkbox_row( 'use_default_rules_when_no_service_rules', __( 'Fallback на default rules', 'walls-delivery-calc' ), $service->use_default_rules_when_no_service_rules ?? true ); ?>
				<?php $this->checkbox_row( 'round_up_to_ruble', __( 'Округлять вверх до рубля', 'walls-delivery-calc' ), $service->round_up_to_ruble ?? true ); ?>
			</table>
			<?php submit_button( __( 'Сохранить службу', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function text_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><input class="regular-text" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>"></td>
		</tr>
		<?php
	}

	/**
	 * @param array<int,string> $options
	 */
	private function select_row( string $name, string $label, string $value, array $options ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( $option ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr>
		<?php
	}

	private function checkbox_row( string $name, string $label, bool $checked ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>> <?php echo esc_html__( 'да', 'walls-delivery-calc' ); ?></label></td>
		</tr>
		<?php
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_service_data(): array {
		return array(
			'service_key' => sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ),
			'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'carrier_key' => sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) ),
			'service_type' => sanitize_key( wp_unslash( $_POST['service_type'] ?? DeliveryService::TYPE_FIXED ) ),
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
			'minimum_price_rub' => max( 0, (float) str_replace( ',', '.', (string) wp_unslash( $_POST['minimum_price_rub'] ?? '1' ) ) ),
			'sort_order' => (int) ( $_POST['sort_order'] ?? 100 ),
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'use_default_rules_when_no_service_rules' => isset( $_POST['use_default_rules_when_no_service_rules'] ) ? 1 : 0,
			'round_up_to_ruble' => isset( $_POST['round_up_to_ruble'] ) ? 1 : 0,
			'deleted' => 0,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function countries_from_post(): array {
		return array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['countries'] ?? '' ) ) ) );
	}

	private function countries_summary( DeliveryService $service ): string {
		if ( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $service->availability_mode ) {
			return __( 'carrier directory', 'walls-delivery-calc' );
		}
		if ( DeliveryService::AVAILABILITY_ALL_COUNTRIES === $service->availability_mode ) {
			return __( 'all', 'walls-delivery-calc' );
		}
		$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

		return array() === $countries ? '-' : implode( ', ', $countries );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function service_rules_url( DeliveryService $service, array $args = array() ): string {
		return admin_url( 'admin.php?' . http_build_query( array_merge( array( 'page' => self::MENU_SLUG, 'service' => $service->service_key, 'tab' => 'rules' ), $args ) ) );
	}

	private function copy_default_rules_to_service( DeliveryService $service ): void {
		$priority = $this->next_service_rule_priority( $service );
		foreach ( $this->rules->get_all_rules_for_target( RuleRepository::TARGET_DEFAULT, '' ) as $rule ) {
			$data = $rule->to_array();
			$data['id'] = null;
			$data['target_type'] = RuleRepository::TARGET_SERVICE;
			$data['target_value'] = $service->service_key;
			$data['priority'] = $priority;
			$data['conditions'] = array_map(
				static function ( RuleCondition $condition ): array {
					$item = $condition->to_array();
					$item['id'] = null;
					$item['rule_id'] = null;

					return $item;
				},
				$rule->conditions
			);
			$this->rules->save_rule( Rule::from_array( $data ) );
			$priority += 10;
		}
	}

	private function next_service_rule_priority( DeliveryService $service ): int {
		$last = 0;
		foreach ( $this->rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, $service->service_key ) as $rule ) {
			$last = max( $last, $rule->priority );
		}

		return $last + 10;
	}
}
