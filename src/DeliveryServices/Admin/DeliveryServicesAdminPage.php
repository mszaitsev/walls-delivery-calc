<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffSyncService;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostCountriesAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Core\PluginEnvironment;
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
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Admin\RuleAdminContext;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;

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
		private ?PickupPointRepository $pickup_points = null,
		private ?RussianPostPickupPointRepository $russian_post_pickup_points = null,
		private ?RussianPostPickupImportStateService $pickup_import_state = null,
		private ?PluginEnvironment $environment = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null,
		private ?CdekSettings $cdek_settings = null,
		private ?CdekApiClient $cdek_api = null,
		private ?CdekTariffRepository $cdek_tariffs = null,
		private ?CdekTariffSyncService $cdek_tariff_sync = null,
		private ?DeliveryQuoteCacheManager $delivery_quote_cache_manager = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wdc_russian_post_pickup_import_status', array( $this, 'ajax_pickup_import_status' ) );
	}

	public function enqueue_assets(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$service = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( $_GET['service'] ) ) : '';
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::MENU_SLUG !== $page || RussianPostDomesticSettings::SERVICE_KEY !== $service || 'russian_post_pickup' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'wdc-russian-post-pickup-import-admin',
			$this->asset_url( 'assets/admin/russian-post-pickup-import.js' ),
			array(),
			$this->asset_version(),
			true
		);
		wp_localize_script(
			'wdc-russian-post-pickup-import-admin',
			'wdcRussianPostPickupImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wdc_russian_post_pickup_import_status' ),
			)
		);
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

	public function ajax_pickup_import_status(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'wdc_russian_post_pickup_import_status', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ) ), 403 );
		}

		$state = $this->pickup_importer instanceof RussianPostPickupImporter
			? $this->pickup_importer->refresh_state_for_status()
			: ( $this->pickup_import_state instanceof RussianPostPickupImportStateService ? $this->pickup_import_state->current() : array() );

		wp_send_json_success( $state );
	}

	private function asset_url( string $path ): string {
		if ( $this->environment instanceof PluginEnvironment ) {
			return $this->environment->plugin_url() . ltrim( $path, '/' );
		}

		return defined( 'WDC_PLUGIN_URL' ) ? WDC_PLUGIN_URL . ltrim( $path, '/' ) : $path;
	}

	private function asset_version(): string {
		if ( $this->environment instanceof PluginEnvironment ) {
			return $this->environment->version();
		}

		return defined( 'WDC_VERSION' ) ? WDC_VERSION : '1';
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( AdminMenu::CAPABILITY ) || ( $_POST['wdc_delivery_services_action'] ?? '' ) === '' ) {
			return;
		}

		check_admin_referer( 'wdc_delivery_services' );
		$action = sanitize_key( wp_unslash( $_POST['wdc_delivery_services_action'] ) );
		if ( in_array( $action, array( 'save', 'save_main', 'save_availability', 'save_calculation', 'save_tariffs', 'save_cdek_tariffs', 'bulk_cdek_tariffs', 'preview_cdek_tariffs_sync', 'confirm_cdek_tariffs_sync', 'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import', 'reset_russian_post_pickup_import', 'save_api_credentials', 'save_shipments', 'save_status_mapping', 'save_cdek_settings', 'save_cdek_calculation', 'check_cdek_connection' ), true ) ) {
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
			if ( in_array( $action, array( 'save_cdek_tariffs', 'bulk_cdek_tariffs', 'preview_cdek_tariffs_sync', 'confirm_cdek_tariffs_sync', 'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import', 'reset_russian_post_pickup_import', 'save_api_credentials', 'save_shipments', 'save_status_mapping', 'save_cdek_settings', 'save_cdek_calculation', 'check_cdek_connection' ), true ) ) {
				$data = array();
			}
			if ( $id > 0 && array() !== $data ) {
				$this->services->update_service( $id, $data );
			} else {
				if ( array() !== $data ) {
					$id = $this->services->create_service( $data );
				}
			}
			if ( in_array( $action, array( 'save', 'save_main', 'save_availability' ), true ) ) {
				$this->countries->replace_countries( $id, $this->countries_from_post() );
			}
			if ( 'save_main' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_russian_post_domestic_main_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && CdekSettings::SERVICE_KEY === $service->service_key && null !== $service->id ) {
					$this->save_cdek_main_settings( (int) $service->id );
				}
			}
			if ( 'save_calculation' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && RussianPostSettings::SERVICE_KEY === $service->service_key && null !== $service->id ) {
					$this->save_russian_post_settings( (int) $service->id );
				}
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_russian_post_domestic_settings( (int) $service->id );
				}
			}
			if ( 'save_shipments' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_shipment_service_settings( (int) $service->id, $service->service_key );
				}
			}
			if ( 'save_status_mapping' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->save_status_mapping_settings( (int) $service->id );
				}
			}
			if ( 'save_tariffs' === $action && $this->settings instanceof DeliveryServiceSettingsRepository ) {
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id ) {
					$this->settings->set_setting( (int) $service->id, 'tariff_variants', $this->sanitize_domestic_tariff_variants_from_post(), 'json' );
				}
			}
			if ( 'save_cdek_tariffs' === $action && $this->cdek_tariffs instanceof CdekTariffRepository ) {
				$this->cdek_tariffs->save_admin_rows( $this->sanitize_cdek_tariffs_from_post() );
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
			}
			if ( 'bulk_cdek_tariffs' === $action && $this->cdek_tariffs instanceof CdekTariffRepository ) {
				$count = $this->handle_cdek_tariffs_bulk_action();
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
				$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
				if ( '' !== $service_key ) {
					wp_safe_redirect(
						add_query_arg(
							array(
								'wdc_cdek_tariffs_notice' => $count > 0 ? ( ( 'delete_all' === sanitize_key( wp_unslash( $_POST['cdek_tariffs_bulk_action'] ?? '' ) ) ) ? 'deleted' : 'updated' ) : 'unchanged',
								'wdc_cdek_tariffs_count' => max( 0, $count ),
							),
							$this->service_tab_url_by_key( $service_key, 'tariffs' )
						)
					);
					exit;
				}
			}
			if ( 'preview_cdek_tariffs_sync' === $action && $this->cdek_tariff_sync instanceof CdekTariffSyncService ) {
				try {
					$preview = $this->cdek_tariff_sync->preview();
					set_transient( $this->cdek_tariff_preview_transient_key(), $preview, defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				} catch ( CdekApiException $exception ) {
					set_transient(
						$this->cdek_tariff_preview_transient_key(),
						array( 'error' => 'Не удалось загрузить тарифы СДЭК: ' . $exception->getMessage() ),
						( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ) * 10
					);
				}
			}
			if ( 'confirm_cdek_tariffs_sync' === $action && $this->cdek_tariff_sync instanceof CdekTariffSyncService ) {
				$preview = get_transient( $this->cdek_tariff_preview_transient_key() );
				$rows = is_array( $preview ) && is_array( $preview['rows'] ?? null ) ? $preview['rows'] : array();
				if ( array() !== $rows ) {
					$this->cdek_tariff_sync->sync_rows( $rows );
				}
				delete_transient( $this->cdek_tariff_preview_transient_key() );
				$this->clear_delivery_quote_cache();
			}
			if ( in_array( $action, array( 'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import' ), true ) && $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
				$this->otpravka_settings->save_from_admin( $_POST );
				$this->save_russian_post_pickup_type_settings( $id );
				if ( 'run_russian_post_pickup_import' === $action && $this->pickup_importer instanceof RussianPostPickupImporter ) {
					$this->pickup_importer->queue_background_import( $this->otpravka_settings->unload_type() );
				}
				if ( in_array( $action, array( 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import' ), true ) && $this->pickup_importer instanceof RussianPostPickupImporter ) {
					$this->handle_russian_post_pickup_file_upload();
				}
			}
			if ( 'reset_russian_post_pickup_import' === $action && $this->pickup_importer instanceof RussianPostPickupImporter ) {
				$this->pickup_importer->reset_stale_or_running_import();
			}
			if ( 'save_api_credentials' === $action && $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
				$this->otpravka_settings->save_from_admin( $_POST );
				$service = $this->services->find_by_service_key( sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) ) );
				if ( $service instanceof DeliveryService && $this->is_domestic_service( $service ) && null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ) {
					$this->save_russian_post_domestic_api_settings( (int) $service->id );
				}
			}
			if ( 'save_cdek_settings' === $action && $this->cdek_settings instanceof CdekSettings ) {
				$this->cdek_api?->clearAllTokenCaches();
				$this->cdek_settings->save_from_admin( $_POST );
				$this->cdek_api?->clearAllTokenCaches();
			}
			if ( 'save_cdek_calculation' === $action && $this->cdek_settings instanceof CdekSettings ) {
				$this->cdek_settings->save_tariff_calculation_from_admin( $_POST );
				$this->clear_delivery_quote_cache();
			}
			if ( 'check_cdek_connection' === $action && $this->cdek_settings instanceof CdekSettings && $this->cdek_api instanceof CdekApiClient ) {
				try {
					$result = $this->cdek_api->checkConnection();
					$this->cdek_settings->save_connection_result( true, (string) $result['message'] );
				} catch ( CdekApiException $exception ) {
					$this->cdek_settings->save_connection_result( false, 'Не удалось подключиться к СДЭК: ' . $exception->getMessage() );
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

		if ( in_array( $action, array( 'save_main', 'save_availability', 'save_calculation', 'save_tariffs', 'save_cdek_tariffs', 'bulk_cdek_tariffs', 'preview_cdek_tariffs_sync', 'confirm_cdek_tariffs_sync', 'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import', 'reset_russian_post_pickup_import', 'save_api_credentials', 'save_shipments', 'save_status_mapping', 'save_cdek_settings', 'save_cdek_calculation', 'check_cdek_connection' ), true ) ) {
			$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
			$tab = match ( $action ) {
				'save_availability' => 'main',
				'save_calculation' => 'calculation',
				'save_tariffs', 'save_cdek_tariffs', 'bulk_cdek_tariffs', 'preview_cdek_tariffs_sync', 'confirm_cdek_tariffs_sync' => 'tariffs',
				'save_russian_post_pickup', 'run_russian_post_pickup_import', 'upload_russian_post_pickup_file_import', 'upload_russian_post_pickup_zip_import', 'reset_russian_post_pickup_import' => 'russian_post_pickup',
				'save_api_credentials' => 'api_credentials',
				'save_shipments' => 'shipments',
				'save_status_mapping' => 'status_mapping',
				'save_cdek_settings', 'check_cdek_connection' => 'cdek_settings',
				'save_cdek_calculation' => 'calculation',
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

	private function handle_russian_post_pickup_file_upload(): void {
		if ( ! $this->pickup_importer instanceof RussianPostPickupImporter ) {
			return;
		}
		$file = $_FILES['russian_post_pickup_file'] ?? ( $_FILES['russian_post_pickup_zip'] ?? null );
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->pickup_import_state?->failed(
				array(
					'source' => 'uploaded_file',
					'type' => $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ? $this->otpravka_settings->unload_type() : 'ALL',
					'errors' => array( 'Pickup import file upload failed or no file was selected.' ),
				)
			);
			return;
		}

		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'russian-post-passport.zip' ) );
		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'zip', 'txt', 'json' ), true ) ) {
			$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Only ZIP, TXT, or JSON files are allowed for Russian Post pickup import.' ) ) );
			return;
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( function_exists( 'wp_check_filetype_and_ext' ) && '' !== $tmp_name ) {
			$checked = wp_check_filetype_and_ext( $tmp_name, $original_name, array( 'zip' => 'application/zip', 'txt' => 'text/plain', 'json' => 'application/json' ) );
			if ( empty( $checked['ext'] ) || ! in_array( (string) $checked['ext'], array( 'zip', 'txt', 'json' ), true ) ) {
				$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Uploaded file failed ZIP/TXT/JSON type validation.' ) ) );
				return;
			}
		}

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$base_dir = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? rtrim( (string) $uploads['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . 'wdc-imports' : sys_get_temp_dir();
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $base_dir );
		} elseif ( ! is_dir( $base_dir ) ) {
			@mkdir( $base_dir, 0755, true );
		}
		$filename = function_exists( 'wp_unique_filename' ) ? wp_unique_filename( $base_dir, $original_name ) : uniqid( 'russian-post-passport-', true ) . '.zip';
		$target = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR . $filename;
		$moved = '' !== $tmp_name && is_uploaded_file( $tmp_name ) ? move_uploaded_file( $tmp_name, $target ) : ( '' !== $tmp_name && is_file( $tmp_name ) && @rename( $tmp_name, $target ) );
		if ( ! $moved || ! is_file( $target ) ) {
			$this->pickup_import_state?->failed( array( 'source' => 'uploaded_file', 'original_upload_name' => $original_name, 'errors' => array( 'Unable to store uploaded pickup import file.' ) ) );
			return;
		}

		$type = $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ? $this->otpravka_settings->unload_type() : 'ALL';
		$queued = 'zip' === $extension
			? $this->pickup_importer->queue_background_import_from_zip( $target, $type, $original_name )
			: $this->pickup_importer->queue_background_import_from_payload( $target, $type, $original_name );
		if ( ! $queued ) {
			$target_size = is_file( $target ) ? (int) filesize( $target ) : 0;
			if ( is_file( $target ) ) {
				if ( function_exists( 'wp_delete_file' ) ) {
					wp_delete_file( $target );
				} else {
					@unlink( $target );
				}
			}
			$this->pickup_import_state?->failed(
				array(
					'source' => 'zip' === $extension ? 'uploaded_zip' : 'uploaded_payload',
					'temp_zip_file' => 'zip' === $extension ? $target : '',
					'payload_file' => 'zip' === $extension ? '' : $target,
					'payload_size' => 'zip' === $extension ? 0 : $target_size,
					'original_upload_name' => $original_name,
					'uploaded_file_size' => $target_size,
					'errors' => array( 'Unable to queue pickup import. Another import may be running.' ),
				)
			);
		}
	}

	private function handle_russian_post_pickup_zip_upload(): void {
		$this->handle_russian_post_pickup_file_upload();
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
			'calculation' => 'Расчет',
			'rules' => 'Правила',
		);
		if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) {
			$tabs['russian_post_countries'] = 'Страны Почты России';
		}
		if ( $this->is_domestic_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
			$tabs['russian_post_pickup'] = 'ПВЗ / ОПС';
			$tabs['api_credentials'] = 'Данные для входа';
			$tabs['shipments'] = 'Отправления';
			$tabs['status_mapping'] = 'Статусы / Mapping';
			$tabs['diagnostics'] = 'Диагностика';
		}
		if ( $this->is_cdek_service( $service ) ) {
			$tabs['tariffs'] = 'Тарифы';
			$tabs['cdek_settings'] = 'Данные для входа';
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
			'calculation' => $this->render_calculation_tab( $service ),
			'rules' => $this->render_rules_tab( $service ),
			'tariffs' => $this->render_tariffs_tab( $service ),
			'russian_post_pickup' => $this->render_russian_post_pickup_tab( $service ),
			'api_credentials' => $this->render_api_credentials_tab( $service ),
			'shipments' => $this->render_shipments_tab( $service ),
			'status_mapping' => $this->render_status_mapping_tab( $service ),
			'diagnostics' => $this->render_diagnostics_tab( $service ),
			'cdek_settings' => $this->render_cdek_settings_tab( $service ),
			'russian_post_countries' => $this->render_russian_post_countries_tab( $service ),
			default => $this->render_main_tab( $service ),
		};
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Назад к списку', 'walls-delivery-calc' ); ?></a></p>
		<?php
	}

	private function render_main_tab( DeliveryService $service ): void {
		$domestic = $this->is_domestic_service( $service ) ? $this->russian_post_domestic_values( $service ) : array();
		$cdek = CdekSettings::SERVICE_KEY === $service->service_key ? $this->cdek_main_values( $service ) : array();
		?>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_main">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<table class="form-table" role="presentation">
				<?php if ( $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
					<?php $this->readonly_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ); ?>
					<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php else : ?>
					<?php $this->text_row( 'service_key', __( 'Service key', 'walls-delivery-calc' ), $service->service_key ); ?>
				<?php endif; ?>
				<?php $this->text_row( 'title', __( 'Название', 'walls-delivery-calc' ), $service->title ); ?>
				<?php if ( $this->services->is_predefined_service_key( $service->service_key ) ) : ?>
					<?php $this->readonly_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ); ?>
					<input type="hidden" name="carrier_key" value="<?php echo esc_attr( $service->carrier_key ); ?>">
				<?php else : ?>
					<?php $this->text_row( 'carrier_key', __( 'Carrier key', 'walls-delivery-calc' ), $service->carrier_key ); ?>
				<?php endif; ?>
				<?php $this->select_row( 'service_type', __( 'Тип', 'walls-delivery-calc' ), $service->service_type, array( DeliveryService::TYPE_API, DeliveryService::TYPE_FIXED, DeliveryService::TYPE_WEIGHT_BASED ) ); ?>
				<?php $this->text_row( 'sort_order', __( 'Sort order', 'walls-delivery-calc' ), (string) $service->sort_order ); ?>
				<?php $this->checkbox_row( 'enabled', __( 'Включена', 'walls-delivery-calc' ), $service->enabled ); ?>
				<?php $this->checkbox_row( 'use_default_rules_when_no_service_rules', __( 'Fallback на default rules', 'walls-delivery-calc' ), $service->use_default_rules_when_no_service_rules ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Доступность', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->select_assoc_row( 'availability_mode', __( 'Доступность', 'walls-delivery-calc' ), $service->availability_mode, $this->availability_mode_options() ); ?>
				<?php if ( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $service->availability_mode ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Справочник перевозчика', 'walls-delivery-calc' ); ?></th><td><?php echo esc_html__( 'Доступность определяется справочником перевозчика.', 'walls-delivery-calc' ); ?> <?php if ( RussianPostSettings::SERVICE_KEY === $service->service_key ) : ?><a class="button" href="<?php echo esc_url( $this->service_tab_url( $service, 'russian_post_countries' ) ); ?>"><?php echo esc_html__( 'Открыть страны Почты России', 'walls-delivery-calc' ); ?></a><?php endif; ?></td></tr>
				<?php endif; ?>
				<?php if ( $this->is_domestic_service( $service ) ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></th><td><code>RU</code><p class="description"><?php echo esc_html__( 'Почта России по РФ доступна только для России.', 'walls-delivery-calc' ); ?></p><input type="hidden" name="countries" value="RU"></td></tr>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'pickup_method_title', __( 'Название варианта до ПВЗ / ОПС', 'walls-delivery-calc' ), (string) ( $domestic['pickup_method_title'] ?? RussianPostDomesticSettings::PICKUP_SERVICE_TITLE ) ); ?>
					<?php $this->text_row( 'courier_method_title', __( 'Название варианта курьером', 'walls-delivery-calc' ), (string) ( $domestic['courier_method_title'] ?? RussianPostDomesticSettings::COURIER_SERVICE_TITLE ) ); ?>
				<?php elseif ( CdekSettings::SERVICE_KEY === $service->service_key ) : ?>
					<tr><th scope="row"><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></th><td><code>RU</code><p class="description"><?php echo esc_html__( 'СДЭК на текущем этапе доступен только для России.', 'walls-delivery-calc' ); ?></p><input type="hidden" name="countries" value="RU"></td></tr>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Названия способов доставки', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'pickup_method_title', __( 'Название варианта до пункта выдачи', 'walls-delivery-calc' ), (string) ( $cdek['pickup_method_title'] ?? CdekSettings::DEFAULT_PICKUP_METHOD_TITLE ) ); ?>
					<?php $this->text_row( 'courier_method_title', __( 'Название варианта курьером', 'walls-delivery-calc' ), (string) ( $cdek['courier_method_title'] ?? CdekSettings::DEFAULT_COURIER_METHOD_TITLE ) ); ?>
				<?php elseif ( in_array( $service->availability_mode, array( DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ), true ) ) : ?>
					<?php $this->text_row( 'countries', __( 'Countries', 'walls-delivery-calc' ), implode( ',', $this->countries->countries( (int) $service->id ) ) ); ?>
				<?php else : ?>
					<input type="hidden" name="countries" value="<?php echo esc_attr( implode( ',', $this->countries->countries( (int) $service->id ) ) ); ?>">
				<?php endif; ?>
			</table>
			<?php submit_button( __( 'Сохранить службу', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_calculation_tab( DeliveryService $service ): void {
		$rp = RussianPostSettings::SERVICE_KEY === $service->service_key ? $this->russian_post_values( $service ) : array();
		$domestic = $this->is_domestic_service( $service ) ? $this->russian_post_domestic_values( $service ) : array();
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
				<?php if ( $this->is_domestic_service( $service ) ) : ?>
					<tr><th colspan="2"><h3><?php echo esc_html__( 'Почта России по РФ', 'walls-delivery-calc' ); ?></h3></th></tr>
					<?php $this->text_row( 'rp_from_postcodes', __( 'Индекс отправки для расчета доставки', 'walls-delivery-calc' ), implode( ',', is_array( $domestic['from_postcodes'] ?? null ) ? $domestic['from_postcodes'] : array() ) ); ?>
					<?php $this->text_row( 'rp_return_postcode', __( 'Индекс возврата для расчета доставки', 'walls-delivery-calc' ), (string) ( $domestic['return_postcode'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_insurance_enabled', __( 'Использовать тарифы с объявленной ценностью', 'walls-delivery-calc' ), ! empty( $domestic['insurance_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_timeout', __( 'Таймаут API, сек', 'walls-delivery-calc' ), (string) ( $domestic['timeout'] ?? 20 ) ); ?>
					<?php $this->text_row( 'rp_vat_rate', __( 'Ставка НДС', 'walls-delivery-calc' ), (string) ( $domestic['vat_rate'] ?? 0.2 ) ); ?>
					<?php $this->checkbox_row( 'rp_fallback_enabled', __( 'Fallback', 'walls-delivery-calc' ), ! empty( $domestic['fallback_enabled'] ) ); ?>
					<?php $this->text_row( 'rp_fallback_text', __( 'Fallback text', 'walls-delivery-calc' ), (string) ( $domestic['fallback_text'] ?? '' ) ); ?>
					<?php $this->checkbox_row( 'rp_cache_until_end_of_day', __( 'Кэш до конца дня', 'walls-delivery-calc' ), ! empty( $domestic['cache_until_end_of_day'] ) ); ?>
					<?php $this->checkbox_row( 'rp_debug', __( 'Debug Почты России', 'walls-delivery-calc' ), ! empty( $domestic['debug'] ) ); ?>
				<?php endif; ?>
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
		<?php if ( $this->is_cdek_service( $service ) && $this->cdek_settings instanceof CdekSettings ) : ?>
		<form method="post" style="max-width: 860px; margin-top: 16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_calculation">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->render_cdek_tariff_calculation_rows(); ?>
			</table>
			<?php submit_button( __( 'Сохранить расчет СДЭК', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php endif; ?>
		<?php
	}

	private function render_russian_post_countries_tab( DeliveryService $service ): void {
		if ( RussianPostSettings::SERVICE_KEY !== $service->service_key || ! $this->russian_post_countries instanceof RussianPostCountriesAdminPage ) {
			return;
		}

		$this->russian_post_countries->render_embedded( $this->service_tab_url( $service, 'russian_post_countries' ) );
	}

	private function render_api_credentials_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) || ! $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$domestic = $this->russian_post_domestic_values( $service );
		$postoffice_codes = $this->otpravka_settings->postoffice_codes();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_api_credentials">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3>Tariff API</h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'rp_api_endpoint', __( 'Tariff API endpoint', 'walls-delivery-calc' ), (string) ( $domestic['api_endpoint'] ?? '' ) ); ?>
				<?php $this->text_row( 'rp_api_token', __( 'Tariff API token, если выдан Почтой', 'walls-delivery-calc' ), (string) ( $domestic['api_token'] ?? '' ) ); ?>
			</table>
			<h3>Otpravka API</h3>
			<table class="form-table" role="presentation">
				<tr><th scope="row">AccessToken</th><td><input class="regular-text" type="password" name="russian_post_otpravka_access_token" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_access_token() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_access_token" value="1"> <?php echo esc_html__( 'очистить сохраненный AccessToken', 'walls-delivery-calc' ); ?></label></td></tr>
				<tr><th scope="row"><label for="russian_post_otpravka_login"><?php echo esc_html__( 'Логин', 'walls-delivery-calc' ); ?></label></th><td><input class="regular-text" id="russian_post_otpravka_login" name="russian_post_otpravka_login" value="<?php echo esc_attr( (string) ( $values[ RussianPostOtpravkaApiSettings::LOGIN_KEY ] ?? '' ) ); ?>"></td></tr>
				<tr><th scope="row"><?php echo esc_html__( 'Пароль', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="password" name="russian_post_otpravka_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label></td></tr>
				<?php $this->text_row( 'russian_post_otpravka_timeout', __( 'Таймаут API, сек.', 'walls-delivery-calc' ), (string) ( $values[ RussianPostOtpravkaApiSettings::TIMEOUT_KEY ] ?? 120 ) ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Индексы места приема для регистрации отправлений', 'walls-delivery-calc' ); ?></th>
					<td>
						<textarea class="large-text" rows="3" name="<?php echo esc_attr( RussianPostOtpravkaApiSettings::POSTOFFICE_CODES_KEY ); ?>"><?php echo esc_textarea( implode( "\n", $postoffice_codes ) ); ?></textarea>
						<p class="description"><?php echo esc_html__( 'Один индекс на строку или через запятую; используются в модалке отправления как postoffice-code и не смешиваются с индексами расчета тарифа.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( 'rp_default_from_postcode', __( 'Индекс отправления по умолчанию', 'walls-delivery-calc' ), (string) ( $domestic['default_from_postcode'] ?? '' ) ); ?>
			</table>
			<h3>Tracking API</h3>
			<table class="form-table" role="presentation">
				<?php $this->text_row( RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY, __( 'Tracking login', 'walls-delivery-calc' ), (string) ( $values[ RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY ] ?? '' ) ); ?>
				<tr><th scope="row"><?php echo esc_html__( 'Tracking password', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="password" name="russian_post_tracking_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_tracking_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_tracking_clear_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_cdek_settings_tab( DeliveryService $service ): void {
		if ( ! $this->is_cdek_service( $service ) || ! $this->cdek_settings instanceof CdekSettings ) {
			return;
		}
		$test_credentials = $this->cdek_settings->credentials_for_environment( CdekSettings::ENV_TEST );
		$production_credentials = $this->cdek_settings->credentials_for_environment( CdekSettings::ENV_PRODUCTION );
		$token_status = $this->cdek_settings->credentials_are_complete()
			? __( 'Данные для активной среды заполнены, token cache будет создан после проверки подключения.', 'walls-delivery-calc' )
			: __( 'Данные для активной среды не заполнены.', 'walls-delivery-calc' );
		$last_check = $this->cdek_settings->last_connection_check();
		$last_status = $this->cdek_settings->last_connection_status();
		$last_message = $this->cdek_settings->last_connection_message();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Данные для входа СДЭК', 'walls-delivery-calc' ); ?></h3>
			<table class="form-table" role="presentation">
				<?php $this->select_assoc_row( CdekSettings::ENVIRONMENT_KEY, __( 'Среда', 'walls-delivery-calc' ), $this->cdek_settings->environment(), array( CdekSettings::ENV_TEST => 'Тестовая', CdekSettings::ENV_PRODUCTION => 'Рабочая' ) ); ?>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Тестовая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( CdekSettings::TEST_ACCOUNT_KEY, __( 'Account / client_id', 'walls-delivery-calc' ), $test_credentials->account ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Secure password / client_secret', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="cdek_test_secure_password" value="" placeholder="<?php echo esc_attr( $this->cdek_settings->has_secure_password( CdekSettings::ENV_TEST ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="cdek_clear_test_secure_password" value="1"> <?php echo esc_html__( 'очистить сохраненный Secure password тестовой среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Рабочая среда', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->text_row( CdekSettings::PRODUCTION_ACCOUNT_KEY, __( 'Account / client_id', 'walls-delivery-calc' ), $production_credentials->account ); ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Secure password / client_secret', 'walls-delivery-calc' ); ?></th>
					<td>
						<input class="regular-text" type="password" name="cdek_production_secure_password" value="" placeholder="<?php echo esc_attr( $this->cdek_settings->has_secure_password( CdekSettings::ENV_PRODUCTION ) ? 'задано' : 'не задано' ); ?>">
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="cdek_clear_production_secure_password" value="1"> <?php echo esc_html__( 'очистить сохраненный Secure password рабочей среды', 'walls-delivery-calc' ); ?></label>
						<p class="description"><?php echo esc_html__( 'Пустое поле не затирает сохраненный секрет.', 'walls-delivery-calc' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php echo esc_html__( 'Проверка подключения', 'walls-delivery-calc' ); ?></h3></th></tr>
				<?php $this->readonly_row( 'cdek_active_environment', __( 'Активная среда', 'walls-delivery-calc' ), $this->cdek_settings->environment_label() ); ?>
				<?php $this->readonly_row( 'cdek_token_cache_status', __( 'Token cache status', 'walls-delivery-calc' ), $token_status ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_CHECK_KEY, __( 'Последняя проверка подключения', 'walls-delivery-calc' ), '' !== $last_check ? $last_check : __( 'не выполнялась', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_STATUS_KEY, __( 'Статус последней проверки', 'walls-delivery-calc' ), '' !== $last_status ? $last_status : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
				<?php $this->readonly_row( CdekSettings::LAST_CONNECTION_MESSAGE_KEY, __( 'Сообщение последней проверки', 'walls-delivery-calc' ), '' !== $last_message ? $last_message : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить данные для входа', 'walls-delivery-calc' ) ); ?>
		</form>
		<form method="post" style="margin-top: 16px; max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_cdek_connection">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Проверить подключение', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_cdek_tariff_calculation_rows(): void {
		if ( ! $this->cdek_settings instanceof CdekSettings ) {
			return;
		}
		$dimensions = $this->cdek_settings->default_package_dimensions_cm();
		?>
		<tr><th colspan="2"><h3><?php echo esc_html__( 'Расчет тарифов', 'walls-delivery-calc' ); ?></h3></th></tr>
		<?php $this->text_row( CdekSettings::SENDER_CITY_CODE_KEY, __( 'Код города отправителя СДЭК', 'walls-delivery-calc' ), (string) $this->cdek_settings->sender_city_code() ); ?>
		<?php $this->text_row( CdekSettings::SHIPMENT_POINT_KEY, __( 'Код ПВЗ отправления СДЭК', 'walls-delivery-calc' ), $this->cdek_settings->shipment_point() ); ?>
		<?php $this->text_row( CdekSettings::SHIPMENT_POINT_ADDRESS_KEY, __( 'Адрес ПВЗ отправления СДЭК', 'walls-delivery-calc' ), $this->cdek_settings->shipment_point_address() ); ?>
		<?php $this->text_row( CdekSettings::SENDER_POSTAL_CODE_KEY, __( 'Индекс отправителя', 'walls-delivery-calc' ), $this->cdek_settings->sender_postal_code() ); ?>
		<?php $this->text_row( CdekSettings::SENDER_CITY_NAME_KEY, __( 'Город отправителя для диагностики', 'walls-delivery-calc' ), $this->cdek_settings->sender_city_name() ); ?>
		<?php $this->text_row( CdekSettings::SENDER_ADDRESS_KEY, __( 'Адрес отправителя СДЭК для тарифов от двери', 'walls-delivery-calc' ), $this->cdek_settings->sender_address() ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_LENGTH_CM_KEY, __( 'Длина упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['length'] ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_WIDTH_CM_KEY, __( 'Ширина упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['width'] ); ?>
		<?php $this->text_row( CdekSettings::DEFAULT_PACKAGE_HEIGHT_CM_KEY, __( 'Высота упаковки по умолчанию, см', 'walls-delivery-calc' ), (string) $dimensions['height'] ); ?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>"><?php echo esc_html__( 'Цена страховки', 'walls-delivery-calc' ); ?></label></th>
			<td>
				<input class="regular-text" id="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>" name="<?php echo esc_attr( CdekSettings::INSURANCE_PERCENT_KEY ); ?>" value="<?php echo esc_attr( (string) $this->cdek_settings->insurance_percent() ); ?>">
				<p class="description"><?php echo esc_html__( 'Процент от стоимости товаров с учетом скидок, который будет автоматически добавлен к стоимости доставки СДЭК перед применением правил расчета. Например, 0,75 означает 0,75%.', 'walls-delivery-calc' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function render_shipments_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$shipment = ( new ShipmentServiceSettings( $this->settings ) )->for_service( $service );
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_shipments">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT, __( 'Срок хранения по умолчанию, дней', 'walls-delivery-calc' ), (string) ( $shipment[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ); ?>
				<?php $this->checkbox_row( ShipmentServiceSettings::SEND_GOODS_ITEMS, __( 'Передавать состав вложения goods.items', 'walls-delivery-calc' ), ! empty( $shipment[ ShipmentServiceSettings::SEND_GOODS_ITEMS ] ) ); ?>
				<?php $this->checkbox_row( ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT, __( 'По умолчанию объединять товары в одну строку', 'walls-delivery-calc' ), ! empty( $shipment[ ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT ] ) ); ?>
				<?php $this->text_row( ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE, __( 'Шаблон названия объединенной строки', 'walls-delivery-calc' ), (string) ( $shipment[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? 'Товары по заказу {order_number}' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить отправления', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_status_mapping_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$settings = null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ? $this->settings->all_settings( (int) $service->id ) : array();
		?>
		<form method="post" style="max-width: 860px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_status_mapping">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->textarea_row( 'status_mapping_json', __( 'Status mapping JSON', 'walls-delivery-calc' ), (string) ( $settings['status_mapping_json'] ?? '{}' ) ); ?>
				<?php $this->text_row( 'status_polling_frequency_minutes', __( 'Polling frequency, minutes', 'walls-delivery-calc' ), (string) ( $settings['status_polling_frequency_minutes'] ?? 60 ) ); ?>
				<?php $this->text_row( 'status_auto_sync_wc_statuses', __( 'WC statuses eligible for auto-sync', 'walls-delivery-calc' ), (string) ( $settings['status_auto_sync_wc_statuses'] ?? 'processing,completed' ) ); ?>
			</table>
			<?php submit_button( __( 'Сохранить mapping статусов', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_diagnostics_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) ) {
			return;
		}
		$settings_count = null !== $service->id && $this->settings instanceof DeliveryServiceSettingsRepository ? count( $this->settings->all_settings( (int) $service->id ) ) : 0;
		$points = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_active() : 0;
		?>
		<table class="widefat striped" style="max-width: 760px; margin-top:16px;">
			<tbody>
				<tr><th scope="row">Service key</th><td><code><?php echo esc_html( $service->service_key ); ?></code></td></tr>
				<tr><th scope="row">Carrier key</th><td><code><?php echo esc_html( $service->carrier_key ); ?></code></td></tr>
				<tr><th scope="row">Settings rows</th><td><?php echo esc_html( (string) $settings_count ); ?></td></tr>
				<tr><th scope="row">Активные ПВЗ / ОПС</th><td><?php echo esc_html( (string) $points ); ?></td></tr>
				<tr><th scope="row">Checkout groups</th><td><code><?php echo esc_html( RussianPostDomesticSettings::checkout_group_id( DeliveryType::PICKUP ) ); ?></code>, <code><?php echo esc_html( RussianPostDomesticSettings::checkout_group_id( DeliveryType::COURIER ) ); ?></code></td></tr>
			</tbody>
		</table>
		<?php
	}

	private function render_tariffs_tab( DeliveryService $service ): void {
		if ( $this->is_cdek_service( $service ) ) {
			$this->render_cdek_tariffs_tab( $service );
			return;
		}
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
			<thead><tr><th>Включен</th><th>Сортировка</th><th>Код object</th><th>Название в checkout</th><th>Тип доставки</th><th>Мин. вес, г</th><th>Макс. вес, г</th><th>Объявленная ценность</th><th>ЕКОМ</th><th>Комментарий администратора</th></tr></thead>
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
						<td><label><input type="checkbox" name="tariff_is_ecom[<?php echo esc_attr( (string) $variant->object_code ); ?>]" value="1" <?php checked( $variant->is_ecom ); ?>> ЕКОМ</label></td>
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

	private function render_cdek_tariffs_tab( DeliveryService $service ): void {
		if ( ! $this->cdek_tariffs instanceof CdekTariffRepository ) {
			echo '<p>' . esc_html__( 'Хранилище тарифов СДЭК недоступно.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		$rows = $this->cdek_tariffs->all();
		$preview = get_transient( $this->cdek_tariff_preview_transient_key() );
		?>
		<div style="margin-top:16px;max-width:1180px;">
			<?php $this->render_cdek_tariffs_bulk_notice(); ?>
			<form method="post" style="margin-bottom:16px;">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="preview_cdek_tariffs_sync">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Загрузить тарифы из СДЭК', 'walls-delivery-calc' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php echo esc_html__( 'Используется метод GET /v2/calculator/alltariffs. Перед записью показывается дифф с текущей таблицей.', 'walls-delivery-calc' ); ?></p>
			</form>
			<?php $this->render_cdek_tariffs_sync_preview( $service, is_array( $preview ) ? $preview : array() ); ?>
			<?php $this->render_cdek_tariffs_bulk_actions( $service ); ?>
			<form method="post">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="save_cdek_tariffs">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<table class="widefat striped">
					<thead><tr><th>Код</th><th>Название СДЭК</th><th>Ограничения</th><th>Название на сайте</th><th>Тип доставки</th><th>Режим тарифа</th><th>Комментарий</th><th>Активен</th><th>Последний sync</th></tr></thead>
					<tbody>
						<?php if ( array() === $rows ) : ?>
							<tr><td colspan="9"><?php echo esc_html__( 'Тарифы еще не загружены.', 'walls-delivery-calc' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $code = (string) $row['tariff_code']; ?>
							<tr>
								<td><code><?php echo esc_html( $code ); ?></code><input type="hidden" name="cdek_tariff_code[]" value="<?php echo esc_attr( $code ); ?>"></td>
								<td><?php echo esc_html( (string) $row['tariff_name_from_cdek'] ); ?></td>
								<td><?php echo wp_kses_post( $this->cdek_tariff_limits_html( $row ) ); ?></td>
								<td><input class="regular-text" name="cdek_tariff_custom_title[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( (string) $row['custom_title'] ); ?>"></td>
								<td><?php $this->cdek_delivery_type_select( $code, (string) $row['delivery_type'] ); ?></td>
								<td><?php $this->cdek_delivery_mode_select( $code, (int) ( $row['delivery_mode'] ?? 0 ) ); ?></td>
								<td><textarea name="cdek_tariff_admin_comment[<?php echo esc_attr( $code ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( (string) $row['admin_comment'] ); ?></textarea></td>
								<td><label><input type="checkbox" name="cdek_tariff_is_active[<?php echo esc_attr( $code ); ?>]" value="1" <?php checked( ! empty( $row['is_active'] ) ); ?>> <?php echo esc_html__( 'Да', 'walls-delivery-calc' ); ?></label></td>
								<td><?php echo esc_html( (string) ( $row['last_sync_at'] ?? '-' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Сохранить тарифы', 'walls-delivery-calc' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function handle_cdek_tariffs_bulk_action(): int {
		if ( ! $this->cdek_tariffs instanceof CdekTariffRepository ) {
			return 0;
		}
		$bulk_action = sanitize_key( wp_unslash( $_POST['cdek_tariffs_bulk_action'] ?? '' ) );
		$mode = max( 0, (int) ( $_POST['cdek_tariffs_delivery_mode'] ?? 0 ) );

		return match ( $bulk_action ) {
			'delete_all' => $this->cdek_tariffs->delete_all(),
			'enable_all' => $this->cdek_tariffs->set_all_active( true ),
			'disable_all' => $this->cdek_tariffs->set_all_active( false ),
			'enable_mode' => $this->cdek_tariffs->set_active_by_delivery_mode( $mode, true ),
			'disable_mode' => $this->cdek_tariffs->set_active_by_delivery_mode( $mode, false ),
			default => 0,
		};
	}

	private function render_cdek_tariffs_bulk_notice(): void {
		$notice = sanitize_key( wp_unslash( $_GET['wdc_cdek_tariffs_notice'] ?? '' ) );
		if ( '' === $notice ) {
			return;
		}
		$count = max( 0, (int) ( $_GET['wdc_cdek_tariffs_count'] ?? 0 ) );
		$message = match ( $notice ) {
			'deleted' => sprintf( __( 'Удалено тарифов СДЭК: %d.', 'walls-delivery-calc' ), $count ),
			'updated' => sprintf( __( 'Обновлено тарифов СДЭК: %d.', 'walls-delivery-calc' ), $count ),
			default => __( 'Тарифы СДЭК не изменены.', 'walls-delivery-calc' ),
		};
		$class = 'unchanged' === $notice ? 'notice notice-warning inline' : 'notice notice-success inline';

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function render_cdek_tariffs_bulk_actions( DeliveryService $service ): void {
		?>
		<div class="notice notice-info inline" style="padding:12px;margin:12px 0 16px;">
			<p><strong><?php echo esc_html__( 'Массовые действия', 'walls-delivery-calc' ); ?></strong></p>
			<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'enable_all', 0, __( 'Включить все', 'walls-delivery-calc' ) ); ?>
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'disable_all', 0, __( 'Выключить все', 'walls-delivery-calc' ) ); ?>
				<?php $this->render_cdek_tariffs_bulk_button( $service, 'delete_all', 0, __( 'Удалить все тарифы', 'walls-delivery-calc' ), 'button button-link-delete', __( 'Удалить все тарифы СДЭК? Это действие нельзя отменить.', 'walls-delivery-calc' ) ); ?>
			</p>
			<?php foreach ( $this->cdek_delivery_mode_bulk_labels() as $mode => $label ) : ?>
				<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:6px 0;">
					<span style="min-width:120px;"><?php echo esc_html( $label ); ?>:</span>
					<?php $this->render_cdek_tariffs_bulk_button( $service, 'enable_mode', $mode, __( 'Включить', 'walls-delivery-calc' ) ); ?>
					<?php $this->render_cdek_tariffs_bulk_button( $service, 'disable_mode', $mode, __( 'Выключить', 'walls-delivery-calc' ) ); ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_cdek_tariffs_bulk_button( DeliveryService $service, string $bulk_action, int $mode, string $label, string $class = 'button button-secondary', string $confirm = '' ): void {
		?>
		<form method="post" style="display:inline;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="bulk_cdek_tariffs">
			<input type="hidden" name="cdek_tariffs_bulk_action" value="<?php echo esc_attr( $bulk_action ); ?>">
			<input type="hidden" name="cdek_tariffs_delivery_mode" value="<?php echo esc_attr( (string) $mode ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"<?php echo '' !== $confirm ? ' onclick="return confirm(' . esc_js( wp_json_encode( $confirm ) ?: "''" ) . ');"' : ''; ?>><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function cdek_delivery_mode_bulk_labels(): array {
		return array(
			4 => __( 'Склад-склад', 'walls-delivery-calc' ),
			3 => __( 'Склад-дверь', 'walls-delivery-calc' ),
			2 => __( 'Дверь-склад', 'walls-delivery-calc' ),
			1 => __( 'Дверь-дверь', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @param array<string,mixed> $preview
	 */
	private function render_cdek_tariffs_sync_preview( DeliveryService $service, array $preview ): void {
		if ( array() === $preview ) {
			return;
		}
		if ( '' !== trim( (string) ( $preview['error'] ?? '' ) ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $preview['error'] ) . '</p></div>';
			return;
		}
		$diff = is_array( $preview['diff'] ?? null ) ? $preview['diff'] : array();
		$new = is_array( $diff['new'] ?? null ) ? $diff['new'] : array();
		$changed = is_array( $diff['changed'] ?? null ) ? $diff['changed'] : array();
		$missing = is_array( $diff['missing'] ?? null ) ? $diff['missing'] : array();
		?>
		<div class="notice notice-info inline" style="padding:12px;margin:12px 0;">
			<p><strong><?php echo esc_html__( 'Предпросмотр синхронизации тарифов СДЭК', 'walls-delivery-calc' ); ?></strong></p>
			<p><?php echo esc_html( sprintf( 'Новые тарифы: %d; изменившиеся: %d; отсутствуют в API: %d.', count( $new ), count( $changed ), count( $missing ) ) ); ?></p>
			<?php $this->render_cdek_tariff_diff_list( 'Новые тарифы будут добавлены', $new ); ?>
			<?php $this->render_cdek_tariff_diff_list( 'Изменившиеся тарифы будут обновлены', $changed ); ?>
			<?php $this->render_cdek_tariff_diff_list( 'Удаленные тарифы отсутствуют в API', $missing ); ?>
			<form method="post">
				<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
				<input type="hidden" name="wdc_delivery_services_action" value="confirm_cdek_tariffs_sync">
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
				<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
				<?php submit_button( __( 'Подтвердить sync тарифов', 'walls-delivery-calc' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function render_cdek_tariff_diff_list( string $title, array $rows ): void {
		if ( array() === $rows ) {
			return;
		}
		echo '<p><strong>' . esc_html( $title ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( array_slice( $rows, 0, 20 ) as $row ) {
			echo '<li><code>' . esc_html( (string) ( $row['tariff_code'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $row['tariff_name_from_cdek'] ?? $row['tariff_name'] ?? '' ) ) . ' (' . esc_html( (string) ( $row['delivery_type'] ?? '' ) ) . ')</li>';
		}
		if ( count( $rows ) > 20 ) {
			echo '<li>' . esc_html( sprintf( '...и еще %d', count( $rows ) - 20 ) ) . '</li>';
		}
		echo '</ul>';
	}

	private function cdek_delivery_type_select( string $code, string $value ): void {
		$value = DeliveryType::COURIER === $value ? DeliveryType::COURIER : DeliveryType::PICKUP;
		?>
		<select name="cdek_tariff_delivery_type[<?php echo esc_attr( $code ); ?>]">
			<option value="<?php echo esc_attr( DeliveryType::PICKUP ); ?>" <?php selected( DeliveryType::PICKUP, $value ); ?>><?php echo esc_html__( 'до ПВЗ', 'walls-delivery-calc' ); ?></option>
			<option value="<?php echo esc_attr( DeliveryType::COURIER ); ?>" <?php selected( DeliveryType::COURIER, $value ); ?>><?php echo esc_html__( 'до двери', 'walls-delivery-calc' ); ?></option>
		</select>
		<?php
	}

	private function cdek_delivery_mode_select( string $code, int $value ): void {
		$value = in_array( $value, array( 1, 2, 3, 4 ), true ) ? $value : 0;
		?>
		<select name="cdek_tariff_delivery_mode[<?php echo esc_attr( $code ); ?>]">
			<?php foreach ( $this->cdek_delivery_mode_options() as $mode => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $mode ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * @return array<int,string>
	 */
	private function cdek_delivery_mode_options(): array {
		return array(
			0 => __( 'Не определен', 'walls-delivery-calc' ),
			1 => __( 'Дверь-дверь', 'walls-delivery-calc' ),
			2 => __( 'Дверь-склад', 'walls-delivery-calc' ),
			3 => __( 'Склад-дверь', 'walls-delivery-calc' ),
			4 => __( 'Склад-склад', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function cdek_tariff_limits_html( array $row ): string {
		$weight = $this->cdek_limit_range( $row['weight_min'] ?? null, $row['weight_max'] ?? null );
		$length = $this->cdek_limit_range( $row['length_min'] ?? null, $row['length_max'] ?? null );
		$width = $this->cdek_limit_range( $row['width_min'] ?? null, $row['width_max'] ?? null );
		$height = $this->cdek_limit_range( $row['height_min'] ?? null, $row['height_max'] ?? null );
		$lines = array();
		if ( '' !== $weight ) {
			$lines[] = 'Вес: ' . $weight . ' кг';
		}
		$dimensions = array();
		if ( '' !== $length ) {
			$dimensions[] = 'Д ' . $length;
		}
		if ( '' !== $width ) {
			$dimensions[] = 'Ш ' . $width;
		}
		if ( '' !== $height ) {
			$dimensions[] = 'В ' . $height;
		}
		if ( array() !== $dimensions ) {
			$lines[] = 'Габариты: ' . implode( ', ', $dimensions ) . ' см';
		}
		if ( array() === $lines ) {
			return '&mdash;';
		}

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	private function cdek_limit_range( mixed $min, mixed $max ): string {
		$min = $this->cdek_limit_number( $min );
		$max = $this->cdek_limit_number( $max );
		if ( null === $min && null === $max ) {
			return '';
		}
		if ( null === $min ) {
			return 'до ' . $max;
		}
		if ( null === $max ) {
			return 'от ' . $min;
		}

		return $min . '–' . $max;
	}

	private function cdek_limit_number( mixed $value ): ?string {
		if ( null === $value || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$number = rtrim( rtrim( number_format( (float) $value, 3, '.', '' ), '0' ), '.' );

		return '' !== $number ? $number : '0';
	}

	private function render_russian_post_pickup_tab( DeliveryService $service ): void {
		if ( ! $this->is_domestic_service( $service ) || ! $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$result = $this->otpravka_settings->last_import_result();
		$state = $this->pickup_importer instanceof RussianPostPickupImporter
			? $this->pickup_importer->refresh_state_for_status()
			: ( $this->pickup_import_state instanceof RussianPostPickupImportStateService ? $this->pickup_import_state->current() : array() );
		$is_busy = in_array( (string) ( $state['status'] ?? 'idle' ), array( 'queued', 'running' ), true );
		$counts = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_by_type() : array();
		$total = $this->russian_post_pickup_points instanceof RussianPostPickupPointRepository ? $this->russian_post_pickup_points->count_active() : 0;
		$point_types = $this->pickup_point_type_settings instanceof RussianPostPickupPointTypeSettings ? $this->pickup_point_type_settings->all() : RussianPostPickupPointTypeSettings::defaults();
		$locked = $this->pickup_importer instanceof RussianPostPickupImporter && $this->pickup_importer->is_locked();
		$schedule_enabled = ! empty( $values[ RussianPostOtpravkaApiSettings::PICKUP_SCHEDULE_ENABLED_KEY ] );
		$next_schedule = $schedule_enabled && function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK ) : false;
		?>
		<form method="post" enctype="multipart/form-data" style="max-width: 960px; margin-top:16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_russian_post_pickup">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3>Типы пунктов выдачи</h3>
			<p class="description">Отключенные типы не попадают в REST-ответы карты. Если выключить все типы, OPS будет включен автоматически.</p>
			<table class="widefat striped" style="max-width: 960px;">
				<thead><tr><th>Тип</th><th>Использовать</th><th>Название в карточке/баллоне/списке</th></tr></thead>
				<tbody>
					<?php foreach ( RussianPostPickupPointTypeSettings::TYPES as $type ) : ?>
						<?php $key = strtolower( $type ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $type ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( "russian_post_domestic_point_type_{$key}_enabled" ); ?>" value="1" <?php checked( ! empty( $point_types[ $type ]['enabled'] ) ); ?>> Использовать</label></td>
							<td><input class="regular-text" type="text" name="<?php echo esc_attr( "russian_post_domestic_point_type_{$key}_label" ); ?>" value="<?php echo esc_attr( (string) ( $point_types[ $type ]['label'] ?? '' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h3>Импорт ПВЗ / ОПС</h3>
			<?php if ( 'failed' === (string) ( $state['status'] ?? '' ) && '' !== $this->pickup_import_state_value( $state, 'errors' ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $this->pickup_import_state_value( $state, 'errors' ) ); ?></p></div>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Статус импорта</th><td><div class="wdc-rp-pickup-import-status" data-wdc-rp-pickup-import-status data-wdc-rp-status="<?php echo esc_attr( (string) ( $state['status'] ?? 'idle' ) ); ?>"><details><summary data-wdc-rp-status-summary><?php echo esc_html( $this->pickup_import_status_summary( $state ) ); ?> <span class="spinner <?php echo $is_busy ? 'is-active' : ''; ?>" data-wdc-rp-spinner></span></summary><table class="widefat striped" style="max-width: 760px; margin-top: 8px;"><tbody><?php foreach ( $this->pickup_import_state_rows() as $key => $label ) : ?><tr><th scope="row"><?php echo esc_html( $label ); ?></th><td data-wdc-rp-field="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $this->pickup_import_state_value( $state, $key ) ); ?></td></tr><?php endforeach; ?></tbody></table><p><button type="button" class="button" data-wdc-rp-refresh-status>Обновить статус</button></p></details></div></td></tr>
				<?php $this->select_row( 'russian_post_pickup_unload_type', 'Тип выгрузки', (string) ( $values[ RussianPostOtpravkaApiSettings::PICKUP_UNLOAD_TYPE_KEY ] ?? 'ALL' ), array( 'ALL', 'OPS', 'PVZ', 'APS' ) ); ?>
				<?php $this->checkbox_row( 'russian_post_pickup_schedule_enabled', 'Обновлять еженедельно', ! empty( $values[ RussianPostOtpravkaApiSettings::PICKUP_SCHEDULE_ENABLED_KEY ] ) ); ?>
				<?php if ( $schedule_enabled ) : ?>
					<tr><th scope="row">Следующий запуск</th><td>
						<?php if ( false !== $next_schedule ) : ?>
							<?php echo esc_html( date_i18n( 'Y-m-d H:i:s', (int) $next_schedule ) ); ?>
						<?php else : ?>
							<span class="notice notice-warning inline" style="display:inline-block;margin:0;padding:4px 8px;"><?php echo esc_html__( 'Расписание включено, но следующий запуск пока не запланирован.', 'walls-delivery-calc' ); ?></span>
						<?php endif; ?>
					</td></tr>
				<?php endif; ?>
				<tr><th scope="row">Блокировка</th><td><?php echo esc_html( $locked ? 'активна' : 'свободна' ); ?></td></tr>
				<tr><th scope="row">Активные точки</th><td><?php echo esc_html( (string) $total ); ?>; OPS: <?php echo esc_html( (string) ( $counts['OPS'] ?? 0 ) ); ?>, PVZ: <?php echo esc_html( (string) ( $counts['PVZ'] ?? 0 ) ); ?>, APS: <?php echo esc_html( (string) ( $counts['APS'] ?? 0 ) ); ?></td></tr>
				<tr><th scope="row">Последний успешный импорт</th><td><?php echo esc_html( $this->otpravka_settings->last_success_at() ?: '-' ); ?></td></tr>
				<tr><th scope="row">Последний статус</th><td><?php echo esc_html( ! empty( $result['success'] ) ? 'успешно' : ( array() === $result ? '-' : 'ошибка' ) ); ?></td></tr>
				<tr><th scope="row">Статистика</th><td>начат: <?php echo esc_html( (string) ( $result['started_at'] ?? '-' ) ); ?>; завершен: <?php echo esc_html( (string) ( $result['finished_at'] ?? '-' ) ); ?>; добавлено: <?php echo esc_html( (string) ( $result['inserted'] ?? 0 ) ); ?>; обновлено: <?php echo esc_html( (string) ( $result['updated'] ?? 0 ) ); ?>; деактивировано: <?php echo esc_html( (string) ( $result['deactivated'] ?? 0 ) ); ?>; пропущено: <?php echo esc_html( (string) ( $result['skipped'] ?? 0 ) ); ?>; ошибки: <?php echo esc_html( $this->translate_import_message( implode( '; ', array_map( 'strval', is_array( $result['errors'] ?? null ) ? $result['errors'] : array() ) ) ) ); ?></td></tr>
			</table>
			<?php submit_button( 'Сохранить настройки импорта', 'secondary', 'submit', false ); ?>
			<button class="button button-primary" type="submit" name="wdc_delivery_services_action" value="run_russian_post_pickup_import" <?php disabled( $is_busy ); ?>>Запустить импорт сейчас</button>
			<?php if ( $is_busy ) : ?>
				<button class="button" type="submit" name="wdc_delivery_services_action" value="reset_russian_post_pickup_import">Отменить / сбросить зависший импорт</button>
			<?php endif; ?>
			<h3 style="margin-top:24px;">Импорт из ZIP/TXT-файла</h3>
			<p class="description">Можно загрузить ZIP, скачанный из API Отправка: <code>https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL</code>. Если ZIP extract не работает в локальной среде, распакуйте ZIP вручную и загрузите .txt/.json файл с <code>passportElements</code>. Файл будет обработан тем же background batch pipeline.</p>
			<p><input type="file" name="russian_post_pickup_file" accept=".zip,.txt,.json"> <button class="button button-primary" type="submit" name="wdc_delivery_services_action" value="upload_russian_post_pickup_file_import" <?php disabled( $is_busy ); ?>>Загрузить ZIP/TXT и начать импорт</button></p>
			<details style="max-width: 760px; margin-top: 8px;">
				<summary>PowerShell: скачать ZIP вручную</summary>
				<pre style="white-space: pre-wrap; background:#f6f7f7; padding:12px; border:1px solid #dcdcde;"><code># === НАСТРОЙКИ ===
$AccessToken = "ВАШ_ACCESS_TOKEN"
$Login       = "ВАШ_LOGIN"
$Password    = "ВАШ_PASSWORD"

# === АВТОРИЗАЦИЯ ДЛЯ X-USER-AUTHORIZATION ===
$BasicAuth = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes("$Login`:$Password")
)

# === КУДА СОХРАНИТЬ ===
$OutFile = "D:\russian-post-passport-all.zip"

# === СКАЧИВАНИЕ ===
Invoke-WebRequest `
  -Uri "https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL" `
  -Headers @{
      "Authorization"        = "AccessToken $AccessToken"
      "X-User-Authorization" = "Basic $BasicAuth"
      "Accept"               = "application/octet-stream"
  } `
  -OutFile $OutFile `
  -TimeoutSec 300

# === РАСПАКОВКА, ЕСЛИ НУЖЕН TXT/JSON FALLBACK ===
Expand-Archive -Path "D:\russian-post-passport-all.zip" -DestinationPath "D:\russian-post-passport-all"
Get-ChildItem "D:\russian-post-passport-all"</code></pre>
			</details>
		</form>
		<?php
	}

	/**
	 * @return array<string,string>
	 */
	private function pickup_import_state_rows(): array {
		return array(
			'status' => 'Статус',
			'stage' => 'Этап',
			'started_at' => 'Начат',
			'finished_at' => 'Завершен',
			'last_activity_at' => 'Последняя активность',
			'type' => 'Тип выгрузки',
			'source' => 'Источник',
			'original_upload_name' => 'Имя загруженного файла',
			'uploaded_file_size' => 'Размер загруженного файла',
			'import_id' => 'ID импорта',
			'download_url' => 'URL загрузки',
			'download_started_at' => 'Загрузка начата',
			'download_duration_ms' => 'Длительность загрузки, мс',
			'download_http_code' => 'HTTP-код загрузки',
			'download_response_message' => 'Ответ загрузки',
			'download_error' => 'Ошибка загрузки',
			'download_backend' => 'Способ загрузки',
			'fallback_used' => 'Использован fallback загрузки',
			'first_backend_error' => 'Ошибка первого способа загрузки',
			'curl_errno' => 'cURL errno',
			'curl_error' => 'Ошибка cURL',
			'temp_file_size' => 'Размер временного ZIP',
			'extract_started_at' => 'Распаковка начата',
			'extract_duration_ms' => 'Длительность распаковки, мс',
			'extract_backend' => 'Способ распаковки',
			'ziparchive_available' => 'ZipArchive доступен',
			'extract_zip_file' => 'ZIP-файл',
			'extract_zip_size' => 'Размер ZIP',
			'extract_success' => 'Распаковка успешна',
			'extracted_payload_entry_name' => 'Файл payload в архиве',
			'extracted_payload_entry_index' => 'Индекс payload в архиве',
			'extracted_payload_file' => 'Payload-файл',
			'extracted_payload_size' => 'Размер payload',
			'extract_error' => 'Ошибка распаковки',
			'payload_file' => 'Payload-файл',
			'payload_size' => 'Размер payload',
			'downloaded' => 'Загружено байт',
			'parsed' => 'Обработано',
			'inserted' => 'Добавлено',
			'updated' => 'Обновлено',
			'deactivated' => 'Деактивировано',
			'skipped' => 'Пропущено',
			'rows_inserted_to_staging' => 'Записано в staging',
			'objects_processed' => 'Объектов обработано',
			'batches_processed' => 'Batch-задач обработано',
			'current_batch_size' => 'Размер текущего batch',
			'last_batch_duration_ms' => 'Последний batch, мс',
			'max_batch_duration_ms' => 'Максимальный batch, мс',
			'payload_offset' => 'Смещение payload',
			'staging_table' => 'Таблица staging',
			'main_table' => 'Основная таблица',
			'backup_table' => 'Backup-таблица',
			'swap_started_at' => 'Swap начат',
			'swap_finished_at' => 'Swap завершен',
			'errors' => 'Ошибки',
			'memory_peak' => 'Пик памяти',
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function pickup_import_status_summary( array $state ): string {
		return sprintf(
			'Статус: %s; этап: %s; обработано: %d; записано: %d',
			$this->translate_import_status( (string) ( $state['status'] ?? 'idle' ) ),
			$this->translate_import_stage( (string) ( $state['stage'] ?? '' ) ),
			(int) ( $state['parsed'] ?? 0 ),
			(int) ( $state['inserted'] ?? 0 )
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function pickup_import_state_value( array $state, string $key ): string {
		if ( 'errors' === $key ) {
			return $this->translate_import_message( implode( '; ', array_map( 'strval', is_array( $state['errors'] ?? null ) ? $state['errors'] : array() ) ) );
		}
		if ( 'status' === $key ) {
			return $this->translate_import_status( (string) ( $state[ $key ] ?? 'idle' ) );
		}
		if ( 'stage' === $key ) {
			return $this->translate_import_stage( (string) ( $state[ $key ] ?? '' ) );
		}
		if ( 'source' === $key ) {
			return $this->translate_import_source( (string) ( $state[ $key ] ?? '' ) );
		}
		if ( 'memory_peak' === $key ) {
			return size_format( max( 0, (int) ( $state[ $key ] ?? 0 ) ) );
		}
		if ( in_array( $key, array( 'fallback_used', 'ziparchive_available', 'extract_success', 'parser_completed' ), true ) ) {
			return ! empty( $state[ $key ] ) ? 'да' : 'нет';
		}

		return $this->translate_import_message( (string) ( $state[ $key ] ?? '' ) );
	}

	private function translate_import_status( string $status ): string {
		$labels = array(
			'' => '-',
			'idle' => 'Ожидание',
			'queued' => 'В очереди',
			'running' => 'Выполняется',
			'success' => 'Успешно',
			'failed' => 'Ошибка',
		);

		return $labels[ $status ] ?? $status;
	}

	private function translate_import_stage( string $stage ): string {
		$labels = array(
			'' => '-',
			'queued' => 'В очереди',
			'download' => 'Загрузка',
			'extract' => 'Распаковка',
			'parse' => 'Обработка',
			'upsert' => 'Запись в staging',
			'deactivate' => 'Финализация',
			'finalize' => 'Финализация',
			'finished' => 'Завершено',
			'failed' => 'Ошибка',
		);

		return $labels[ $stage ] ?? $stage;
	}

	private function translate_import_source( string $source ): string {
		$labels = array(
			'' => '-',
			'api_download' => 'Автоматическая загрузка из API',
			'uploaded_zip' => 'Загруженный ZIP',
			'uploaded_payload' => 'Загруженный TXT/JSON',
			'uploaded_file' => 'Загруженный файл',
		);

		return $labels[ $source ] ?? $source;
	}

	private function translate_import_message( string $message ): string {
		if ( '' === $message ) {
			return '';
		}

		$replacements = array(
			'Unable to queue pickup import. Another import may be running.' => 'Не удалось поставить импорт в очередь. Возможно, уже выполняется другой импорт.',
			'Unable to queue ZIP import. Another import may be running.' => 'Не удалось поставить импорт ZIP в очередь. Возможно, уже выполняется другой импорт.',
			'Unable to schedule background import job.' => 'Не удалось запланировать фоновую задачу импорта.',
			'Unable to schedule background import batch job.' => 'Не удалось запланировать фоновую batch-задачу импорта.',
			'Uploaded TXT/JSON payload file is missing or empty.' => 'Загруженный TXT/JSON-файл отсутствует или пуст.',
			'Uploaded TXT/JSON payload file is missing, empty, or has an invalid extension.' => 'Загруженный TXT/JSON-файл отсутствует, пуст или имеет недопустимое расширение.',
			'Uploaded ZIP file is missing or empty.' => 'Загруженный ZIP-файл отсутствует или пуст.',
			'ZIP extract failed. Try uploading extracted TXT/JSON payload.' => 'Не удалось распаковать ZIP. Попробуйте загрузить распакованный TXT/JSON-файл.',
			'PHP ZipArchive extension is not available.' => 'На сервере недоступно расширение PHP ZipArchive. Проверьте PHP extension zip.',
			'ZIP does not contain JSON/TXT passport payload.' => 'ZIP не содержит JSON/TXT-файл с passportElements.',
			'Unable to open ZIP archive.' => 'Не удалось открыть ZIP-архив.',
			'ZipArchive code:' => 'Код ZipArchive:',
			'Download stage timed out/stale.' => 'Этап загрузки завис или превысил лимит ожидания.',
			'API download is unstable in this environment. Use manual ZIP upload import.' => 'Автоматическая загрузка через API нестабильна в этом окружении. Используйте ручную загрузку ZIP.',
			'Extract stage timed out/stale. Check PHP ZipArchive extension or use extracted JSON/TXT import.' => 'Этап распаковки завис или превысил лимит ожидания. Проверьте PHP ZipArchive или загрузите распакованный TXT/JSON.',
			'Batch stage timed out/stale.' => 'Batch-обработка зависла или превысила лимит ожидания.',
			'Import was manually cancelled/reset by admin.' => 'Импорт был вручную отменен/сброшен администратором.',
			'Pickup import file upload failed or no file was selected.' => 'Не удалось загрузить файл импорта или файл не выбран.',
			'Only ZIP, TXT, or JSON files are allowed for Russian Post pickup import.' => 'Для импорта ПВЗ Почты России разрешены только ZIP, TXT или JSON-файлы.',
			'Uploaded file failed ZIP/TXT/JSON type validation.' => 'Загруженный файл не прошел проверку типа ZIP/TXT/JSON.',
			'Unable to store uploaded pickup import file.' => 'Не удалось сохранить загруженный файл импорта ПВЗ.',
		);

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $message );
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

	private function readonly_row( string $name, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><code><?php echo esc_html( $value ); ?></code><p class="description"><?php echo esc_html__( 'Техническое поле системной службы, не редактируется.', 'walls-delivery-calc' ); ?></p></td>
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
			'availability_mode' => sanitize_key( wp_unslash( $_POST['availability_mode'] ?? DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ),
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

	private function save_russian_post_domestic_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_domestic_main_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_main_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_cdek_main_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_cdek_main_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_domestic_api_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}

		foreach ( $this->sanitize_russian_post_domestic_api_settings_from_post() as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_russian_post_pickup_type_settings( int $service_id ): void {
		if ( $service_id <= 0 || ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		$type_settings = $this->pickup_point_type_settings ?? new RussianPostPickupPointTypeSettings();
		foreach ( $type_settings->sanitize_admin_values( $_POST ) as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_shipment_service_settings( int $service_id, string $service_key ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		foreach ( ShipmentServiceSettings::sanitize_from_post( $_POST, $service_key ) as $key => $data ) {
			$this->settings->set_setting( $service_id, $key, $data['value'], $data['format'] );
		}
	}

	private function save_status_mapping_settings( int $service_id ): void {
		if ( ! $this->settings instanceof DeliveryServiceSettingsRepository ) {
			return;
		}
		$json = trim( (string) wp_unslash( $_POST['status_mapping_json'] ?? '{}' ) );
		$this->settings->set_setting( $service_id, 'status_mapping_json', '' !== $json ? $json : '{}', 'string' );
		$this->settings->set_setting( $service_id, 'status_polling_frequency_minutes', max( 5, min( 1440, (int) ( $_POST['status_polling_frequency_minutes'] ?? 60 ) ) ), 'number' );
		$this->settings->set_setting( $service_id, 'status_auto_sync_wc_statuses', sanitize_text_field( wp_unslash( $_POST['status_auto_sync_wc_statuses'] ?? 'processing,completed' ) ), 'string' );
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
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_settings_from_post(): array {
		$number = static fn ( string $key, float $default = 0 ): float => (float) str_replace( ',', '.', (string) wp_unslash( $_POST[ $key ] ?? (string) $default ) );
		$int = static fn ( string $key, int $default = 0 ): int => max( 0, (int) ( $_POST[ $key ] ?? $default ) );
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$postcodes_raw = preg_split( '/[\s,;]+/', (string) wp_unslash( $_POST['rp_from_postcodes'] ?? '' ) ) ?: array();
		$postcodes = array_values(
			array_unique(
				array_filter(
					array_map( static fn ( mixed $value ): string => preg_replace( '/\D+/', '', (string) $value ) ?? '', $postcodes_raw ),
					static fn ( string $value ): bool => 1 === preg_match( '/^\d{6}$/', $value )
				)
			)
		);
		if ( array() === $postcodes ) {
			$postcodes = array( '630005' );
		}

		return array(
			'from_postcodes' => array( 'value' => $postcodes, 'format' => 'json' ),
			'return_postcode' => array( 'value' => $string( 'rp_return_postcode', $postcodes[0] ), 'format' => 'string' ),
			'insurance_enabled' => array( 'value' => isset( $_POST['rp_insurance_enabled'] ), 'format' => 'bool' ),
			'timeout' => array( 'value' => max( 1, min( 60, $int( 'rp_timeout', 20 ) ) ), 'format' => 'number' ),
			'vat_rate' => array( 'value' => max( 0, $number( 'rp_vat_rate', 0.2 ) ), 'format' => 'number' ),
			'fallback_enabled' => array( 'value' => isset( $_POST['rp_fallback_enabled'] ), 'format' => 'bool' ),
			'fallback_text' => array( 'value' => $string( 'rp_fallback_text', 'Стоимость доставки рассчитает менеджер' ), 'format' => 'string' ),
			'cache_until_end_of_day' => array( 'value' => isset( $_POST['rp_cache_until_end_of_day'] ), 'format' => 'bool' ),
			'debug' => array( 'value' => isset( $_POST['rp_debug'] ), 'format' => 'bool' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_main_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$pickup_title = trim( $string( 'pickup_method_title', RussianPostDomesticSettings::PICKUP_SERVICE_TITLE ) );
		$courier_title = trim( $string( 'courier_method_title', RussianPostDomesticSettings::COURIER_SERVICE_TITLE ) );

		return array(
			'pickup_method_title' => array( 'value' => '' !== $pickup_title ? $pickup_title : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE, 'format' => 'string' ),
			'courier_method_title' => array( 'value' => '' !== $courier_title ? $courier_title : RussianPostDomesticSettings::COURIER_SERVICE_TITLE, 'format' => 'string' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_cdek_main_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$pickup_title = trim( $string( 'pickup_method_title', CdekSettings::DEFAULT_PICKUP_METHOD_TITLE ) );
		$courier_title = trim( $string( 'courier_method_title', CdekSettings::DEFAULT_COURIER_METHOD_TITLE ) );

		return array(
			'pickup_method_title' => array( 'value' => '' !== $pickup_title ? $pickup_title : CdekSettings::DEFAULT_PICKUP_METHOD_TITLE, 'format' => 'string' ),
			'courier_method_title' => array( 'value' => '' !== $courier_title ? $courier_title : CdekSettings::DEFAULT_COURIER_METHOD_TITLE, 'format' => 'string' ),
		);
	}

	/**
	 * @return array<string,array{value:mixed,format:string}>
	 */
	private function sanitize_russian_post_domestic_api_settings_from_post(): array {
		$string = static fn ( string $key, string $default = '' ): string => sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
		$url = static fn ( string $key, string $default = '' ): string => function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) wp_unslash( $_POST[ $key ] ?? $default ) ) : filter_var( (string) wp_unslash( $_POST[ $key ] ?? $default ), FILTER_SANITIZE_URL );

		return array(
			'api_endpoint' => array( 'value' => $url( 'rp_api_endpoint', 'https://tariff.pochta.ru/v2/calculate/tariff/delivery' ), 'format' => 'string' ),
			'api_token' => array( 'value' => $string( 'rp_api_token', '' ), 'format' => 'string' ),
			'default_from_postcode' => array( 'value' => $string( 'rp_default_from_postcode', '630005' ), 'format' => 'string' ),
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
	 * @return array<string,mixed>
	 */
	private function russian_post_domestic_values( DeliveryService $service ): array {
		$defaults = array(
			'api_endpoint' => 'https://tariff.pochta.ru/v2/calculate/tariff/delivery',
			'api_token' => '',
			'pickup_method_title' => RussianPostDomesticSettings::PICKUP_SERVICE_TITLE,
			'courier_method_title' => RussianPostDomesticSettings::COURIER_SERVICE_TITLE,
			'from_postcodes' => array( '630005' ),
			'default_from_postcode' => '630005',
			'return_postcode' => '630005',
			'insurance_enabled' => false,
			'timeout' => 20,
			'vat_rate' => 0.2,
			'fallback_enabled' => false,
			'fallback_text' => 'Стоимость доставки рассчитает менеджер',
			'cache_until_end_of_day' => true,
			'debug' => false,
		);
		$saved = $this->settings instanceof DeliveryServiceSettingsRepository && null !== $service->id ? $this->settings->all_settings( (int) $service->id ) : array();

		return array_merge( $defaults, $saved );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cdek_main_values( DeliveryService $service ): array {
		$defaults = array(
			'pickup_method_title' => CdekSettings::DEFAULT_PICKUP_METHOD_TITLE,
			'courier_method_title' => CdekSettings::DEFAULT_COURIER_METHOD_TITLE,
		);
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
		$is_ecom = is_array( $_POST['tariff_is_ecom'] ?? null ) ? wp_unslash( $_POST['tariff_is_ecom'] ) : array();
		$admin_comment = is_array( $_POST['tariff_admin_comment'] ?? null ) ? wp_unslash( $_POST['tariff_admin_comment'] ) : array();
		return array_map(
			static function ( $variant ) use ( $enabled, $sort, $title, $min_weight, $max_weight, $is_ecom, $admin_comment ): array {
				$data = $variant->to_array();
				$code = (string) $variant->object_code;
				$data['enabled'] = isset( $enabled[ $code ] );
				$data['sort_order'] = max( 0, (int) ( $sort[ $code ] ?? $variant->sort_order ) );
				$custom_title = isset( $title[ $code ] ) ? sanitize_text_field( (string) $title[ $code ] ) : $variant->title;
				$data['title'] = '' !== trim( $custom_title ) ? $custom_title : $variant->title;
				$data['min_weight_g'] = isset( $min_weight[ $code ] ) && '' !== trim( (string) $min_weight[ $code ] ) ? max( 0, (int) $min_weight[ $code ] ) : null;
				$data['max_weight_g'] = isset( $max_weight[ $code ] ) && '' !== trim( (string) $max_weight[ $code ] ) ? max( 0, (int) $max_weight[ $code ] ) : null;
				$data['is_ecom'] = isset( $is_ecom[ $code ] );
				$data['admin_comment'] = isset( $admin_comment[ $code ] ) ? sanitize_text_field( (string) $admin_comment[ $code ] ) : '';

				return $data;
			},
			( new RussianPostDomesticTariffVariantResolver() )->defaults()
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_cdek_tariffs_from_post(): array {
		$codes = is_array( $_POST['cdek_tariff_code'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_code'] ) : array();
		$custom_titles = is_array( $_POST['cdek_tariff_custom_title'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_custom_title'] ) : array();
		$delivery_types = is_array( $_POST['cdek_tariff_delivery_type'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_delivery_type'] ) : array();
		$delivery_modes = is_array( $_POST['cdek_tariff_delivery_mode'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_delivery_mode'] ) : array();
		$comments = is_array( $_POST['cdek_tariff_admin_comment'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_admin_comment'] ) : array();
		$active = is_array( $_POST['cdek_tariff_is_active'] ?? null ) ? wp_unslash( $_POST['cdek_tariff_is_active'] ) : array();
		$rows = array();
		foreach ( $codes as $code ) {
			$code = preg_replace( '/[^0-9A-Za-z_-]+/', '', trim( (string) $code ) ) ?? '';
			if ( '' === $code ) {
				continue;
			}
			$rows[] = array(
				'tariff_code' => $code,
				'custom_title' => sanitize_text_field( (string) ( $custom_titles[ $code ] ?? '' ) ),
				'delivery_type' => DeliveryType::COURIER === (string) ( $delivery_types[ $code ] ?? '' ) ? DeliveryType::COURIER : DeliveryType::PICKUP,
				'delivery_mode' => in_array( (int) ( $delivery_modes[ $code ] ?? 0 ), array( 1, 2, 3, 4 ), true ) ? (int) $delivery_modes[ $code ] : 0,
				'admin_comment' => sanitize_textarea_field( (string) ( $comments[ $code ] ?? '' ) ),
				'is_active' => isset( $active[ $code ] ),
			);
		}

		return $rows;
	}

	private function cdek_tariff_preview_transient_key(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return 'wdc_cdek_tariff_sync_preview_' . $user_id;
	}

	private function clear_delivery_quote_cache(): void {
		if ( $this->delivery_quote_cache_manager instanceof DeliveryQuoteCacheManager ) {
			$this->delivery_quote_cache_manager->clear_all_delivery_cache();
		}
	}

	private function is_domestic_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && RussianPostDomesticSettings::SERVICE_KEY === $service->service_key && RussianPostDomesticSettings::CARRIER_KEY === $service->carrier_key;
	}

	private function is_cdek_service( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService && CdekSettings::SERVICE_KEY === $service->service_key && CdekSettings::CARRIER_KEY === $service->carrier_key;
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
