<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Packaging\PackagingApplicationResult;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
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
		private RuleRepository $rules,
		private ?DeliveryServiceSettingsRepository $settings = null,
		private ?RussianPostSettings $russian_post_settings = null,
		private ?RussianPostCountriesAdminPage $russian_post_countries = null,
		private ?RussianPostInternationalCarrier $russian_post_carrier = null,
		private ?RuleAppliedRateBuilder $rule_builder = null,
		private ?DeliveryServiceManager $manager = null,
		private ?PackagingWeightCalculator $packaging_calculator = null
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
		if ( in_array( $action, array( 'save', 'save_main', 'save_availability', 'save_calculation' ), true ) ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$data = match ( $action ) {
				'save_main' => $this->sanitize_main_data(),
				'save_availability' => $this->sanitize_availability_data(),
				'save_calculation' => $this->sanitize_calculation_data(),
				default => $this->sanitize_service_data(),
			};
			if ( $id > 0 ) {
				$this->services->update_service( $id, $data );
			} else {
				$id = $this->services->create_service( $data );
			}
			if ( 'save' === $action || 'save_availability' === $action ) {
				$this->countries->replace_countries( $id, $this->countries_from_post() );
			}
			if ( 'save_calculation' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && RussianPostSettings::SERVICE_KEY === $service->service_key && null !== $service->id ) {
					$this->save_russian_post_settings( (int) $service->id );
				}
			}
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

		if ( in_array( $action, array( 'save_main', 'save_availability', 'save_calculation' ), true ) ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$tab = match ( $action ) {
				'save_availability' => 'availability',
				'save_calculation' => 'calculation',
				default => 'main',
			};
			if ( '' !== $service_key ) {
				wp_safe_redirect( $this->service_tab_url_by_key( $service_key, $tab ) );
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
							<td><?php echo esc_html( $this->availability_mode_label( $service->availability_mode ) ); ?></td>
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
		if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) {
			$tabs['russian_post_countries'] = 'Страны Почты России';
		}
		?>
		<h2><?php echo esc_html( $service->title ); ?></h2>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_key => $tab ) : ?>
				<a class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&service=' . rawurlencode( $service->service_key ) . '&tab=' . rawurlencode( $tab_key ) ) ); ?>"><?php echo esc_html( $tab ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		match ( $current_tab ) {
			'availability' => $this->render_availability_tab( $service ),
			'calculation' => $this->render_calculation_tab( $service ),
			'rules' => $this->render_rules_tab( $service ),
			'russian_post_countries' => $this->render_russian_post_countries_tab( $service ),
			default => $this->render_main_tab( $service ),
		};
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Назад к списку', 'walls-delivery-calc' ); ?></a></p>
		<?php
	}

	private function render_main_tab( DeliveryService $service ): void {
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_main">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ); ?>
				<?php $this->text_row( 'title', __( 'Название', 'walls-delivery-calc' ), $service->title ); ?>
				<?php $this->text_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ); ?>
				<?php $this->select_row( 'service_type', __( 'Тип', 'walls-delivery-calc' ), $service->service_type, array( DeliveryService::TYPE_API, DeliveryService::TYPE_FIXED, DeliveryService::TYPE_WEIGHT_BASED ) ); ?>
				<?php $this->text_row( 'sort_order', __( 'Sort order', 'walls-delivery-calc' ), (string) $service->sort_order ); ?>
				<?php $this->checkbox_row( 'enabled', __( 'Включена', 'walls-delivery-calc' ), $service->enabled ); ?>
				<?php $this->checkbox_row( 'use_default_rules_when_no_service_rules', __( 'Fallback на default rules', 'walls-delivery-calc' ), $service->use_default_rules_when_no_service_rules ); ?>
			</table>
			<?php submit_button( __( 'Сохранить службу', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_availability_tab( DeliveryService $service ): void {
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_availability">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->select_assoc_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode, $this->availability_mode_options() ); ?>
				<?php if ( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $service->availability_mode ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Справочник перевозчика', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html__( 'Доступность определяется справочником перевозчика.', 'walls-delivery-calc' ); ?> <?php if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) : ?><a class="button" href="<?php echo esc_url( $this->service_tab_url( $service, 'russian_post_countries' ) ); ?>"><?php echo esc_html__( 'Открыть страны Почты России', 'walls-delivery-calc' ); ?></a><?php endif; ?></td></tr>
				<?php endif; ?>
				<?php if ( in_array( $service->availability_mode, array( DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ), true ) ) : ?>
					<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), implode( ',', $this->countries->countries( (int) $service->id ) ) ); ?>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Сохранить доступность', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_calculation_tab( DeliveryService $service ): void {
		$rp = RussianPostSettings::SERVICE_KEY === $service->service_key ? $this->russian_post_values( $service ) : array();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_calculation">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->checkbox_row( 'round_up_to_ruble', __( 'Округлять вверх до рубля', 'walls-delivery-calc' ), $service->round_up_to_ruble ); ?>
				<?php $this->text_row( 'minimum_price_rub', __( 'Минимальная цена, руб.', 'walls-delivery-calc' ), (string) $service->minimum_price_rub ); ?>
				<?php $this->checkbox_row( 'include_packaging_weight', __( 'Учитывать вес упаковки', 'walls-delivery-calc' ), $service->include_packaging_weight ); ?>
				<?php $this->select_assoc_row( 'packaging_weight_mode', __( 'Способ учета веса упаковки', 'walls-delivery-calc' ), $service->packaging_weight_mode, $this->packaging_weight_mode_options() ); ?>
				<?php $this->textarea_row( 'pickup_customer_comment', __( 'Комментарий для покупателя — доставка до ПВЗ', 'walls-delivery-calc' ), $service->pickup_customer_comment ); ?>
				<?php $this->textarea_row( 'courier_customer_comment', __( 'Комментарий для покупателя — курьерская доставка', 'walls-delivery-calc' ), $service->courier_customer_comment ); ?>
				<?php if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) : ?>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Почта России', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'rp_api_endpoint', __( 'API endpoint тарифа', 'walls-delivery-calc' ), (string) ( $rp['api_endpoint'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_country_endpoint', __( 'API endpoint стран', 'walls-delivery-calc' ), (string) ( $rp['country_endpoint'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_origin_postcode', __( 'Индекс отправления', 'walls-delivery-calc' ), (string) ( $rp['origin_postcode'] ?? '' ) ); ?>
					<?php $this->text_row( 'rp_object_code', __( 'Код объекта отправления', 'walls-delivery-calc' ), (string) ( $rp['object_code'] ?? 4031 ) ); ?>
					<?php $this->select_row( 'rp_isavia', __( 'Авиадоставка', 'walls-delivery-calc' ), (string) ( $rp['isavia'] ?? 0 ), array( '0', '1' ) ); ?>
					<?php $this->text_row( 'rp_timeout', __( 'Таймаут API, сек', 'walls-delivery-calc' ), (string) ( $rp['timeout'] ?? 20 ) ); ?>
					<?php $this->text_row( 'rp_vat_rate', __( 'Ставка НДС', 'walls-delivery-calc' ), (string) ( $rp['vat_rate'] ?? 0.2 ) ); ?>
					<?php $this->text_row( 'rp_max_package_weight_g', __( 'Максимальный вес сервиса, г', 'walls-delivery-calc' ), (string) ( $rp['max_package_weight_g'] ?? 19990 ) ); ?>
					<?php $this->checkbox_row( 'rp_fallback_enabled', __( 'Fallback', 'walls-delivery-calc' ), ! empty( $rp['fallback_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_fallback_text', __( 'Fallback text', 'walls-delivery-calc' ), (string) ( $rp['fallback_text'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_cache_until_end_of_day', __( 'Кэш до конца дня', 'walls-delivery-calc' ), ! empty( $rp['cache_until_end_of_day'] ) ); ?>
					<?php $this->checkbox_row( 'rp_auto_refresh_countries_if_empty', __( 'Автообновление стран, если пусто', 'walls-delivery-calc' ), ! empty( $rp['auto_refresh_countries_if_empty'] ) ); ?>
					<?php $this->checkbox_row( 'rp_debug', __( 'Debug Почты России', 'walls-delivery-calc' ), ! empty( $rp['debug'] ) ); ?>
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Сохранить расчет', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_russian_post_countries_tab( DeliveryService $service ): void {
		if ( RussianPostSettings::SERVICE_KEY !== $service->service_key || ! $this->russian_post_countries instanceof RussianPostCountriesAdminPage ) {
			return;
		}

		$this->russian_post_countries->render_embedded( $this->service_tab_url( $service, 'russian_post_countries' ) );
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
		$this->rules_admin->set_service_simulation_runner( fn( array $input, array $rules ): array => $this->simulate_service_rules( $service, $input, $rules ) );
		$this->rules_admin->render_embedded_for_context(
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
				<?php $this->select_assoc_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, $this->availability_mode_options() ); ?>
				<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), $service instanceof DeliveryService ? implode( ',', $this->countries->countries( (int) $service->id ) ) : '' ); ?>
				<?php $this->text_row( 'minimum_price_rub', __( 'Минимальная цена, руб.', 'walls-delivery-calc' ), (string) ( $service->minimum_price_rub ?? 1 ) ); ?>
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

	private function textarea_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><textarea class="large-text" rows="3" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"><?php echo esc_textarea( $value ); ?></textarea></td>
		</tr>
		<?php
	}

	/**
	 * @param array<int,string> $options
	 */
	private function select_row( string $name, string $label, string $value, array $options ): void {
		$this->select_assoc_row( $name, $label, $value, array_combine( $options, $options ) ?: array() );
	}

	/**
	 * @param array<string,string> $options
	 */
	private function select_assoc_row( string $name, string $label, string $value, array $options ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td><select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
				<?php foreach ( $options as $option => $label_text ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $value, (string) $option ); ?>><?php echo esc_html( $label_text ); ?></option>
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
	 * @return array<string,mixed>
	 */
	private function sanitize_main_data(): array {
		return array(
			'service_key' => sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ),
			'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'carrier_key' => sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) ),
			'service_type' => sanitize_key( wp_unslash( $_POST['service_type'] ?? DeliveryService::TYPE_FIXED ) ),
			'sort_order' => (int) ( $_POST['sort_order'] ?? 100 ),
			'enabled' => isset( $_POST['enabled'] ) ? 1 : 0,
			'use_default_rules_when_no_service_rules' => isset( $_POST['use_default_rules_when_no_service_rules'] ) ? 1 : 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_availability_data(): array {
		return array(
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sanitize_calculation_data(): array {
		return array(
			'round_up_to_ruble' => isset( $_POST['round_up_to_ruble'] ) ? 1 : 0,
			'minimum_price_rub' => max( 0, (float) str_replace( ',', '.', (string) wp_unslash( $_POST['minimum_price_rub'] ?? '1' ) ) ),
			'include_packaging_weight' => isset( $_POST['include_packaging_weight'] ) ? 1 : 0,
			'packaging_weight_mode' => DeliveryService::normalize_packaging_weight_mode( sanitize_key( wp_unslash( $_POST['packaging_weight_mode'] ?? DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) ) ),
			'pickup_customer_comment' => $this->sanitize_textarea( $_POST['pickup_customer_comment'] ?? '' ),
			'courier_customer_comment' => $this->sanitize_textarea( $_POST['courier_customer_comment'] ?? '' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function packaging_weight_mode_options(): array {
		return array(
			DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT => __( 'Прибавлять к общему весу посылки', 'walls-delivery-calc' ),
			DeliveryService::PACKAGING_WEIGHT_PACKAGE_ITEM => __( 'Добавлять отдельной строкой «Упаковка»', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function availability_mode_options(): array {
		return array(
			DeliveryService::AVAILABILITY_CARRIER_DIRECTORY => __( 'Справочник перевозчика', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_SELECTED_COUNTRIES => __( 'Только выбранные страны', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_ALL_COUNTRIES => __( 'Все страны', 'walls-delivery-calc' ),
			DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED => __( 'Все страны, кроме выбранных', 'walls-delivery-calc' ),
		);
	}

	private function availability_mode_label( string $mode ): string {
		return $this->availability_mode_options()[ $mode ] ?? $mode;
	}

	private function sanitize_textarea( mixed $value ): string {
		$value = wp_unslash( $value );

		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : trim( strip_tags( (string) $value ) );
	}

	private function save_russian_post_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_settings_from_post(): array {
		$number = static fn ( string $key, float $default = 0 ): float => (float) str_replace( ',', '.', (string) wp_unslash( $_POST[ $key ] ?? (string) $default ) );
		$int = static fn ( string $key, int $default = 0 ): int => max( 0, (int) ( $_POST[ $key ] ?? $default ) );
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$url = static fn ( string $key, string $default = '' ): string => function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) wp_unslash( $_POST[ $key ] ?? $default ) ) : filter_var( (string) wp_unslash( $_POST[ $key ] ?? $default ), FILTER_SANITIZE_URL );
		return array(
			'api_endpoint' => array( 'value' => $url( 'rp_api_endpoint', 'https://tariff.pochta.ru/v2/calculate/tariff' ), 'format' => 'string' ),
			'country_endpoint' => array( 'value' => $url( 'rp_country_endpoint', 'https://tariff.pochta.ru/v2/dictionary/country' ), 'format' => 'string' ),
			'origin_postcode' => array( 'value' => $string( 'rp_origin_postcode', '630005' ), 'format' => 'string' ),
			'object_code' => array( 'value' => max( 1, $int( 'rp_object_code', 4031 ) ), 'format' => 'number' ),
			'isavia' => array( 'value' => ! empty( $_POST['rp_isavia'] ) ? 1 : 0, 'format' => 'number' ),
			'timeout' => array( 'value' => max( 1, min( 60, $int( 'rp_timeout', 20 ) ) ), 'format' => 'number' ),
			'vat_rate' => array( 'value' => max( 0, $number( 'rp_vat_rate', 0.2 ) ), 'format' => 'number' ),
			'max_package_weight_g' => array( 'value' => max( 1, min( 100000, $int( 'rp_max_package_weight_g', 19990 ) ) ), 'format' => 'number' ),
			'fallback_enabled' => array( 'value' => isset( $_POST['rp_fallback_enabled'] ), 'format' => 'bool' ),
			'fallback_text' => array( 'value' => $string( 'rp_fallback_text', 'Стоимость доставки рассчитает менеджер' ), 'format' => 'string' ),
			'cache_until_end_of_day' => array( 'value' => isset( $_POST['rp_cache_until_end_of_day'] ), 'format' => 'bool' ),
			'auto_refresh_countries_if_empty' => array( 'value' => isset( $_POST['rp_auto_refresh_countries_if_empty'] ), 'format' => 'bool' ),
			'debug' => array( 'value' => isset( $_POST['rp_debug'] ), 'format' => 'bool' ),
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
			return __( 'Справочник перевозчика', 'walls-delivery-calc' );
		}
		if ( DeliveryService::AVAILABILITY_ALL_COUNTRIES === $service->availability_mode ) {
			return __( 'Все страны', 'walls-delivery-calc' );
		}
		if ( DeliveryService::AVAILABILITY_SELECTED_COUNTRIES === $service->availability_mode ) {
			$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

			return array() === $countries ? __( 'Только выбранные страны', 'walls-delivery-calc' ) : implode( ', ', $countries );
		}
		if ( DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED === $service->availability_mode ) {
			$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

			return array() === $countries ? __( 'Все страны, кроме выбранных', 'walls-delivery-calc' ) : __( 'Все, кроме: ', 'walls-delivery-calc' ) . implode( ', ', $countries );
		}
		$countries = null === $service->id ? array() : $this->countries->countries( (int) $service->id );

		return array() === $countries ? '-' : implode( ', ', $countries );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function russian_post_values( DeliveryService $service ): array {
		$defaults = $this->russian_post_settings instanceof RussianPostSettings ? $this->russian_post_settings->defaults() : array();
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	private function service_tab_url( DeliveryService $service, string $tab ): string {
		return $this->service_tab_url_by_key( $service->service_key, $tab );
	}

	private function service_tab_url_by_key( string $service_key, string $tab ): string {
		return admin_url( 'admin.php?' . http_build_query( array( 'page' => self::MENU_SLUG, 'service' => $service_key, 'tab' => $tab ) ) );
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

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,Rule> $rules
	 * @return array<string,mixed>
	 */
	private function simulate_service_rules( DeliveryService $service, array $input, array $rules ): array {
		if ( RussianPostSettings::SERVICE_KEY !== $service->service_key || ! $this->russian_post_carrier instanceof RussianPostInternationalCarrier || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'notice' => __( 'Симуляция для этой службы пока не поддерживается.', 'walls-delivery-calc' ) );
		}

		$country = strtoupper( sanitize_text_field( (string) ( $input['country'] ?? 'US' ) ) );
		$weight = max( 0, (int) ( $input['weight'] ?? 1000 ) );
		$order_total = (float) str_replace( ',', '.', (string) ( $input['order_total'] ?? 1000 ) );
		$date = sanitize_text_field( (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) ) );
		$item = new PackageItem( 'SIM', 'Simulation', 1, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ), $weight );
		$package = Package::from_items( array( $item ), 0, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ) );
		$packaging = $this->packaging_calculator instanceof PackagingWeightCalculator
			? $this->packaging_calculator->apply_to_package( $package, $service )
			: new PackagingApplicationResult( $package->weight_g, 0, $package->get_total_weight_g(), $service->include_packaging_weight, $service->packaging_weight_mode, $package );
		$package = $packaging->package;
		$request = new QuoteRequest( $country, new Address( country_code: $country ), $package, '', Money::from_rubles( $order_total ), $date );
		$quote = $this->russian_post_carrier->quote( $request );
		$rate = $quote->rates[0] ?? null;
		if ( ! $rate instanceof DeliveryRate ) {
			return array(
				'base_price' => '-',
				'final_price' => '-',
				'source' => $quote->source . ' / ' . $quote->error_code,
				'notice' => $quote->error_message ?: __( 'Служба не вернула тариф для выбранной страны.', 'walls-delivery-calc' ),
			);
		}

		$context = new RuleEvaluationContext( Money::from_rubles( $order_total ), $rate->price, $package, $request->destination, $rate->delivery_type, '', $date, array(), array( 'original_delivery_days' => $rate->delivery_days?->min_days ?? 0 ) );
		$applied = $this->rule_builder->apply( $rate, $context, $rules );
		$final = $applied['rate'];
		$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $final, $service ) : $final;

		return array(
			'base_price' => $rate->price->get_rubles() . ' ' . $rate->price->get_currency(),
			'final_price' => $processed->price->get_rubles() . ' ' . $processed->price->get_currency(),
			'delivery_days' => null !== $processed->delivery_days ? trim( (string) ( $processed->delivery_days->min_days ?? '-' ) . '-' . (string) ( $processed->delivery_days->max_days ?? '-' ), '-' ) : '-',
			'products_weight_g' => $packaging->original_products_weight_g,
			'packaging_weight_g' => $packaging->packaging_weight_g,
			'package_weight_with_packaging_g' => $packaging->final_package_weight_g,
			'packaging_weight_mode' => $packaging->packaging_weight_mode,
			'source' => implode( ' / ', array_filter( array( $quote->source, ! empty( $rate->meta['fallback_reason'] ) ? 'fallback: ' . $rate->meta['fallback_reason'] : '', ! empty( $rate->meta['cache_hit'] ) ? 'cache hit' : 'cache miss' ) ) ),
			'audit' => $applied['audit'],
			'notice' => array() === $rules ? __( 'Для службы не настроены собственные правила.', 'walls-delivery-calc' ) : '',
		);
	}
}
