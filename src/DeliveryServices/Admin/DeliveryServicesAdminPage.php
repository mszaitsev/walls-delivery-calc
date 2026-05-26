<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Packaging\PackagingApplicationResult;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPassportPointNormalizer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
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
		private ?PackagingWeightCalculator $packaging_calculator = null,
		private ?RussianPostDomesticCarrier $russian_post_domestic_carrier = null,
		private ?RussianPostOtpravkaApiSettings $otpravka_settings = null,
		private ?RussianPostPickupImporter $pickup_importer = null,
		private ?PickupPointRepository $pickup_points = null
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
		if ( in_array( $action, array( 'save', 'save_main', 'save_availability', 'save_calculation', 'save_tariffs', 'save_russian_post_pickup', 'run_russian_post_pickup_import' ), true ) ) {
			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$data = match ( $action ) {
				'save_main' => $this->sanitize_main_data(),
				'save_availability' => $this->sanitize_availability_data(),
				'save_calculation' => $this->sanitize_calculation_data(),
				default => $this->sanitize_service_data(),
			};
			if ( 'save_tariffs' === $action ) {
				$data = array();
			}
			if ( in_array( $action, array( 'save_russian_post_pickup', 'run_russian_post_pickup_import' ), true ) ) {
				$data = array();
			}
			if ( $id > 0 && array() !== $data ) {
				$this->services->update_service( $id, $data );
			} else {
				if ( array() !== $data ) {
					$id = $this->services->create_service( $data );
				}
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
			if ( 'save_tariffs' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->settings->set_setting( (int) $service->id, 'tariff_variants', $this->sanitize_domestic_tariff_variants_from_post(), 'json' );
				}
			}
			if ( in_array( $action, array( 'save_russian_post_pickup', 'run_russian_post_pickup_import' ), true ) && $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
				$this->otpravka_settings->save_from_admin( $_POST );
				if ( 'run_russian_post_pickup_import' === $action && $this->pickup_importer instanceof RussianPostPickupImporter ) {
					$this->pickup_importer->import( $this->otpravka_settings->unload_type() );
				}
			}
		}

		if ( 'toggle' === $action ) {
			$this->services->update_service( (int) $_POST['id'], array( 'enabled' => empty( $_POST['enabled'] ) ? 1 : 0 ) );
		}

		if ( 'delete' === $action ) {
			$service = $this->services->find_by_id( (int) $_POST['id'] );
			if ( ! $service instanceof DeliveryService || ! $this->services->is_predefined_service_key( $service->service_key ) ) {
				$this->services->soft_delete_service( (int) $_POST['id'] );
			}
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

		if ( in_array( $action, array( 'save_main', 'save_availability', 'save_calculation', 'save_tariffs', 'save_russian_post_pickup', 'run_russian_post_pickup_import' ), true ) ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$tab = match ( $action ) {
				'save_availability' => 'availability',
				'save_calculation' => 'calculation',
				'save_tariffs' => 'tariffs',
				'save_russian_post_pickup', 'run_russian_post_pickup_import' => 'russian_post_pickup',
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
								<?php if ( ! $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
									<input type="hidden" name="wdc_delivery_services_action" value="delete">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
									<button class="button button-link-delete"><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
								</form>
								<?php else : ?>
									<span class="description"><?php echo esc_html__( 'Системная служба', 'walls-delivery-calc' ); ?></span>
								<?php endif; ?>
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
		if ( $this->is_domestic_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
		}
		?>
		<h2><?php echo esc_html( $service->title ); ?></h2>
		<?php
		if ( RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $service->service_key ) {
			$tabs['russian_post_pickup'] = 'ПВЗ / ОПС';
		}
		?>
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
			'tariffs' => $this->render_tariffs_tab( $service ),
			'russian_post_pickup' => $this->render_russian_post_pickup_tab( $service ),
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

	private function render_tariffs_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$variants = $this->domestic_tariff_variants( $service );
		?>
		<form method="post" style="margin-top:16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_tariffs">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
		<table class="widefat striped">
			<thead><tr><th>Включен</th><th>Сортировка</th><th>Код object</th><th>Название в checkout</th><th>Тип доставки</th><th>Мин. вес, г</th><th>Макс. вес, г</th><th>Объявленная ценность</th><th>Комментарий администратора</th></tr></thead>
			<tbody>
				<?php foreach ( $variants as $variant ) : ?>
					<tr>
						<td><input type="checkbox" name="tariff_enabled[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="1" <?php checked( $variant->enabled ); ?>></td>
						<td><input class="small-text" name="tariff_sort[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( (string) $variant->sort_order ); ?>"></td>
						<td><?php echo esc_html( (string) $variant->object_code ); ?></td>
						<td><input class="regular-text" name="tariff_title[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( $variant->title ); ?>"></td>
						<td><?php echo esc_html( DeliveryType::COURIER === $variant->delivery_type ? 'Курьер' : 'До отделения' ); ?></td>
						<td><input class="small-text" type="number" min="0" name="tariff_min_weight_g[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( null === $variant->min_weight_g ? '' : (string) $variant->min_weight_g ); ?>"></td>
						<td><input class="small-text" type="number" min="0" name="tariff_max_weight_g[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( null === $variant->max_weight_g ? '' : (string) $variant->max_weight_g ); ?>"></td>
						<td><?php echo esc_html( $variant->requires_declared_value ? ( $variant->always_available ? 'ОЦ, всегда доступен' : 'ОЦ' ) : 'Нет' ); ?></td>
						<td><input class="regular-text" name="tariff_admin_comment[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="<?php echo esc_attr( $variant->admin_comment ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button( __( 'Сохранить тарифы', 'walls-delivery-calc' ) ); ?>
		</form>
		<p><?php echo esc_html__( 'Лимиты веса можно оставить пустыми. Расчет использует вес товаров с учетом настроек упаковки службы; pack для API всегда равен 99.', 'walls-delivery-calc' ); ?></p>
		<?php
	}

	private function render_russian_post_pickup_tab( DeliveryService $service ): void {
		if ( RussianPostDomesticSettings::PICKUP_SERVICE_KEY !== $service->service_key || ! $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$result = $this->otpravka_settings->last_import_result();
		$counts = $this->pickup_points instanceof PickupPointRepository ? $this->pickup_points->count_by_type( RussianPostPassportPointNormalizer::CARRIER_KEY ) : array();
		$total = $this->pickup_points instanceof PickupPointRepository ? $this->pickup_points->count_active( RussianPostPassportPointNormalizer::CARRIER_KEY ) : 0;
		$locked = $this->pickup_importer instanceof RussianPostPickupImporter && $this->pickup_importer->is_locked();
		?>
		<form method="post" style="max-width: 960px; margin-top:16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_russian_post_pickup">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3>API Отправка Почты России</h3>
			<p class="description">Эти реквизиты используются для выгрузки ПВЗ/ОПС и позже будут использоваться для регистрации посылок в личном кабинете Почты России.</p>
			<table class="form-table" role="presentation">
				<tr><th scope="row">AccessToken</th><td><input class="regular-text" type="password" name="russian_post_otpravka_access_token" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_access_token() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_access_token" value="1"> очистить сохраненный AccessToken</label></td></tr>
				<?php $this->text_row( 'russian_post_otpravka_login', 'Login', (string) ( $values[ RussianPostOtpravkaApiSettings::LOGIN_KEY ] ?? '' ) ); ?>
				<tr><th scope="row">Password</th><td><input class="regular-text" type="password" name="russian_post_otpravka_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_password" value="1"> очистить сохраненный пароль</label></td></tr>
				<tr><th scope="row">Basic key</th><td><input class="regular-text" type="password" name="russian_post_otpravka_basic_key" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_basic_key() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_basic_key" value="1"> очистить сохраненный Basic key</label></td></tr>
				<?php $this->text_row( 'russian_post_otpravka_timeout', 'Timeout, sec', (string) ( $values[ RussianPostOtpravkaApiSettings::TIMEOUT_KEY ] ?? 20 ) ); ?>
			</table>
			<h3>Импорт ПВЗ / ОПС</h3>
			<table class="form-table" role="presentation">
				<?php $this->select_row( 'russian_post_pickup_unload_type', 'Unload type', (string) ( $values[ RussianPostOtpravkaApiSettings::PICKUP_UNLOAD_TYPE_KEY ] ?? 'ALL' ), array( 'ALL', 'OPS', 'PVZ', 'APS' ) ); ?>
				<?php $this->checkbox_row( 'russian_post_pickup_schedule_enabled', 'Ежедневный импорт', ! empty( $values[ RussianPostOtpravkaApiSettings::PICKUP_SCHEDULE_ENABLED_KEY ] ) ); ?>
				<tr><th scope="row">Lock</th><td><?php echo esc_html( $locked ? 'активен' : 'свободен' ); ?></td></tr>
				<tr><th scope="row">Активные точки</th><td><?php echo esc_html( (string) $total ); ?>; OPS: <?php echo esc_html( (string) ( $counts['OPS'] ?? 0 ) ); ?>, PVZ: <?php echo esc_html( (string) ( $counts['PVZ'] ?? 0 ) ); ?>, APS: <?php echo esc_html( (string) ( $counts['APS'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">Последний успешный импорт</th><td><?php echo esc_html( $this->otpravka_settings->last_success_at() ?: '-' ); ?></td></tr>
				<tr><th scope="row">Последний статус</th><td><?php echo esc_html( ! empty( $result['success'] ) ? 'success' : ( array() === $result ? '-' : 'failed' ) ); ?></td></tr>
				<tr><th scope="row">Stats</th><td>started_at: <?php echo esc_html( (string) ( $result['started_at'] ?? '-' ) ); ?>; finished_at: <?php echo esc_html( (string) ( $result['finished_at'] ?? '-' ) ); ?>; inserted: <?php echo esc_html( (string) ( $result['inserted'] ?? 0 ) ); ?>; updated: <?php echo esc_html( (string) ( $result['updated'] ?? 0 ) ); ?>; deactivated: <?php echo esc_html( (string) ( $result['deactivated'] ?? 0 ) ); ?>; skipped: <?php echo esc_html( (string) ( $result['skipped'] ?? 0 ) ); ?>; errors: <?php echo esc_html( implode( '; ', array_map( 'strval', is_array( $result['errors'] ?? null ) ? $result['errors'] : array() ) ) ); ?></td></tr>
			</table>
			<?php submit_button( 'Сохранить настройки импорта', 'secondary', 'submit', false ); ?>
			<button class="button button-primary" type="submit" name="wdc_delivery_services_action" value="run_russian_post_pickup_import">Запустить импорт сейчас</button>
		</form>
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

	/**
	 * @return array<int,\WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant>
	 */
	private function domestic_tariff_variants( DeliveryService $service ): array {
		$defaults = ( new RussianPostDomesticTariffVariantResolver() )->defaults();
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->get_setting( (int) $service->id, 'tariff_variants', array() ) : array();
		if ( ! is_array( $saved ) || array() === $saved ) {
			return $defaults;
		}
		$by_code = array();
		foreach ( $saved as $row ) {
			if ( is_array( $row ) && isset( $row['object_code'] ) ) {
				$by_code[ (int) $row['object_code'] ] = $row;
			}
		}

		return array_map(
			static fn ( $variant ) => isset( $by_code[ $variant->object_code ] )
				? \WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant::from_array( array_merge( $variant->to_array(), $by_code[ $variant->object_code ] ) )
				: $variant,
			$defaults
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_domestic_tariff_variants_from_post(): array {
		$enabled = is_array( $_POST['tariff_enabled'] ?? null ) ? wp_unslash( $_POST['tariff_enabled'] ) : array();
		$sort = is_array( $_POST['tariff_sort'] ?? null ) ? wp_unslash( $_POST['tariff_sort'] ) : array();
		$title = is_array( $_POST['tariff_title'] ?? null ) ? wp_unslash( $_POST['tariff_title'] ) : array();
		$min_weight = is_array( $_POST['tariff_min_weight_g'] ?? null ) ? wp_unslash( $_POST['tariff_min_weight_g'] ) : array();
		$max_weight = is_array( $_POST['tariff_max_weight_g'] ?? null ) ? wp_unslash( $_POST['tariff_max_weight_g'] ) : array();
		$admin_comment = is_array( $_POST['tariff_admin_comment'] ?? null ) ? wp_unslash( $_POST['tariff_admin_comment'] ) : array();
		return array_map(
			static function ( $variant ) use ( $enabled, $sort, $title, $min_weight, $max_weight, $admin_comment ): array {
				$data = $variant->to_array();
				$code = (string) $variant->object_code;
				$data['enabled'] = isset( $enabled[ $code ] );
				$data['sort_order'] = max( 0, (int) ( $sort[ $code ] ?? $variant->sort_order ) );
				$custom_title = isset( $title[ $code ] ) ? sanitize_text_field( (string) $title[ $code ] ) : $variant->title;
				$data['title'] = '' !== trim( $custom_title ) ? $custom_title : $variant->title;
				$data['min_weight_g'] = isset( $min_weight[ $code ] ) && '' !== trim( (string) $min_weight[ $code ] ) ? max( 0, (int) $min_weight[ $code ] ) : null;
				$data['max_weight_g'] = isset( $max_weight[ $code ] ) && '' !== trim( (string) $max_weight[ $code ] ) ? max( 0, (int) $max_weight[ $code ] ) : null;
				$data['admin_comment'] = isset( $admin_comment[ $code ] ) ? sanitize_text_field( (string) $admin_comment[ $code ] ) : '';

				return $data;
			},
			( new RussianPostDomesticTariffVariantResolver() )->defaults()
		);
	}

	private function is_domestic_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && (
			RussianPostDomesticSettings::CARRIER_KEY === $service->carrier_key
			|| in_array( $service->service_key, array( RussianPostDomesticSettings::PICKUP_SERVICE_KEY, RussianPostDomesticSettings::COURIER_SERVICE_KEY ), true )
		);
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
		if ( $this->is_domestic_service( $service ) ) {
			return $this->simulate_domestic_service_rules( $service, $input, $rules );
		}

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

		$context = new RuleEvaluationContext( Money::from_rubles( $order_total ), $rate->price, $package, $request->destination, $rate->delivery_type, '', $date, array(), array( 'original_delivery_days' => $rate->delivery_days?->min_days ?? 0, 'original_delivery_min_days' => $rate->delivery_days?->min_days, 'original_delivery_max_days' => $rate->delivery_days?->max_days ) );
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

	private function simulate_domestic_service_rules( DeliveryService $service, array $input, array $rules ): array {
		if ( ! $this->russian_post_domestic_carrier instanceof RussianPostDomesticCarrier || ! $this->rule_builder instanceof RuleAppliedRateBuilder ) {
			return array( 'notice' => __( 'Симуляция domestic-службы пока не поддерживается.', 'walls-delivery-calc' ) );
		}

		$weight = max( 0, (int) ( $input['weight'] ?? 1000 ) );
		$order_total = (float) str_replace( ',', '.', (string) ( $input['order_total'] ?? 1000 ) );
		$date = sanitize_text_field( (string) ( $input['date'] ?? gmdate( 'Y-m-d' ) ) );
		$postcode = preg_replace( '/\D+/', '', (string) ( $input['postal_code'] ?? '' ) ) ?? '';
		$city = sanitize_text_field( (string) ( $input['city'] ?? '' ) );
		$fias_id = sanitize_text_field( (string) ( $input['location_fias_id'] ?? '' ) );
		$item = new PackageItem( 'SIM', 'Simulation', 1, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ), $weight );
		$package = Package::from_items( array( $item ), 0, Money::from_rubles( $order_total ), Money::from_rubles( $order_total ) );
		$packaging = $this->packaging_calculator instanceof PackagingWeightCalculator
			? $this->packaging_calculator->apply_to_package( $package, $service )
			: new PackagingApplicationResult( $package->weight_g, 0, $package->get_total_weight_g(), $service->include_packaging_weight, $service->packaging_weight_mode, $package );
		$package = $packaging->package;
		$request = new QuoteRequest(
			'RU',
			new Address( country_code: 'RU', city: $city, settlement: $city, postcode: $postcode, raw_address: $city, fias_id: $fias_id ),
			$package,
			'',
			Money::from_rubles( $order_total ),
			$date,
			array(
				'service_key' => $service->service_key,
				'postcode' => $postcode,
				'fias_id' => $fias_id,
				'city' => $city,
			)
		);
		$quote = $this->russian_post_domestic_carrier->quote( $request );
		$rows = array();
		$audit = array();
		foreach ( $quote->rates as $rate ) {
			if ( ! $rate instanceof DeliveryRate ) {
				continue;
			}
			$context = new RuleEvaluationContext( Money::from_rubles( $order_total ), $rate->price, $package, $request->destination, $rate->delivery_type, '', $date, array(), array_merge( $rate->meta, array( 'original_delivery_days' => $rate->delivery_days->min_days ?? $rate->delivery_days->max_days, 'original_delivery_min_days' => $rate->delivery_days->min_days, 'original_delivery_max_days' => $rate->delivery_days->max_days ) ) );
			$applied = $this->rule_builder->apply( $rate, $context, $rules );
			$processed = $this->manager instanceof DeliveryServiceManager ? $this->manager->post_process_rate( $applied['rate'], $service ) : $applied['rate'];
			$rows[] = array(
				'object_code' => $rate->tariff_key,
				'title' => $rate->tariff_name,
				'api_price' => $rate->price->get_rubles() . ' ' . $rate->price->get_currency(),
				'api_delivery_days' => $this->range_label( $rate->delivery_days ),
				'final_price' => $processed->price->get_rubles() . ' ' . $processed->price->get_currency(),
				'final_delivery_days' => $this->range_label( $processed->delivery_days ),
			);
			$audit[ $rate->tariff_key ] = $applied['audit'];
		}

		return array(
			'tariffs' => $rows,
			'products_weight_g' => $packaging->original_products_weight_g,
			'packaging_weight_g' => $packaging->packaging_weight_g,
			'package_weight_with_packaging_g' => $packaging->final_package_weight_g,
			'packaging_weight_mode' => $packaging->packaging_weight_mode,
			'source' => $quote->source . ( '' !== $quote->error_code ? ' / ' . $quote->error_code : '' ),
			'skipped_tariffs' => is_array( $quote->raw_reference['skipped_tariffs'] ?? null ) ? $quote->raw_reference['skipped_tariffs'] : array(),
			'audit' => $audit,
			'notice' => array() === $rows ? ( $quote->error_message ?: $quote->error_code ) : '',
		);
	}

	private function range_label( DateRange $range ): string {
		$label = DeliveryDaysFormatter::format( $range );

		return '' !== $label ? $label : '-';
	}
}
