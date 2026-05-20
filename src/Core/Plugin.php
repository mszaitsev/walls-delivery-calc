<?php
declare(strict_types=1);

namespace WallsShop\WDC\Core;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Admin\AdminNotices;
use WallsShop\WDC\Calendar\Admin\CalendarAdminPage;
use WallsShop\WDC\Calendar\Services\CalendarScheduler;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\DemoCarrier;
use WallsShop\WDC\Checkout\Admin\CheckoutSimulationPage;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Services\GarChangesService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\WooCommerce\HPOSCompatibility;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private PluginEnvironment $environment;

	private Container $container;

	public function __construct( PluginEnvironment $environment, ?Container $container = null ) {
		$this->environment = $environment;
		$this->container   = $container ?? new Container();
	}

	public function register(): void {
		$this->register_services();
		$this->register_hooks();
	}

	public function container(): Container {
		return $this->container;
	}

	private function register_services(): void {
		$this->container->register( PluginEnvironment::class, fn(): PluginEnvironment => $this->environment );
		$this->container->register( PluginConstants::class, fn(): PluginConstants => new PluginConstants( $this->environment ) );
		$this->container->register( FeatureFlags::class, fn(): FeatureFlags => new FeatureFlags() );
		$this->container->register( Logger::class, fn(): Logger => new Logger() );
		$this->container->register( SettingsRepository::class, fn(): SettingsRepository => new SettingsRepository() );
		$this->container->register( EncryptionService::class, fn(): EncryptionService => new EncryptionService() );
		$this->container->register( MigrationManager::class, fn(): MigrationManager => new MigrationManager( $this->environment->version(), $this->environment->plugin_dir() . 'database/migrations' ) );
		$this->container->register( ActionScheduler::class, fn(): ActionScheduler => new ActionScheduler( $this->container->get( Logger::class ) ) );
		$this->container->register( CalendarRepository::class, fn(): CalendarRepository => new CalendarRepository() );
		$this->container->register( LocationRepository::class, fn(): LocationRepository => new LocationRepository() );
		$this->container->register( RuleRepository::class, fn(): RuleRepository => new RuleRepository() );
		$this->container->register( ConditionEvaluator::class, fn(): ConditionEvaluator => new ConditionEvaluator() );
		$this->container->register( RuleEvaluator::class, fn(): RuleEvaluator => new RuleEvaluator( $this->container->get( ConditionEvaluator::class ) ) );
		$this->container->register( RuleEngine::class, fn(): RuleEngine => new RuleEngine( $this->container->get( RuleEvaluator::class ) ) );
		$this->container->register( RuleSimulator::class, fn(): RuleSimulator => new RuleSimulator( $this->container->get( RuleEngine::class ) ) );
		$this->container->register( DemoCarrier::class, fn(): DemoCarrier => new DemoCarrier() );
		$this->container->register(
			CarrierRegistry::class,
			function (): CarrierRegistry {
				$registry = new CarrierRegistry();
				$registry->register( $this->container->get( DemoCarrier::class ) );

				return $registry;
			}
		);
		$this->container->register( QuoteCache::class, fn(): QuoteCache => new QuoteCache() );
		$this->container->register( RateSorter::class, fn(): RateSorter => new RateSorter() );
		$this->container->register( FallbackRateFactory::class, fn(): FallbackRateFactory => new FallbackRateFactory() );
		$this->container->register( RuleAppliedRateBuilder::class, fn(): RuleAppliedRateBuilder => new RuleAppliedRateBuilder( $this->container->get( RuleEngine::class ) ) );
		$this->container->register( CheckoutLogger::class, fn(): CheckoutLogger => new CheckoutLogger( $this->container->get( Logger::class ) ) );
		$this->container->register( CarrierExecutionGuard::class, fn(): CarrierExecutionGuard => new CarrierExecutionGuard( $this->container->get( CheckoutLogger::class ) ) );
		$this->container->register(
			CheckoutOrchestrator::class,
			fn(): CheckoutOrchestrator => new CheckoutOrchestrator(
				$this->container->get( CarrierRegistry::class ),
				$this->container->get( RuleAppliedRateBuilder::class ),
				$this->container->get( RateSorter::class ),
				$this->container->get( FallbackRateFactory::class ),
				$this->container->get( CarrierExecutionGuard::class ),
				$this->container->get( CheckoutLogger::class ),
				$this->container->get( QuoteCache::class )
			)
		);
		$this->container->register( WooCommerceRateMapper::class, fn(): WooCommerceRateMapper => new WooCommerceRateMapper() );
		$this->container->register( NewShippingMethod::class, fn(): NewShippingMethod => new NewShippingMethod( $this->container->get( CheckoutOrchestrator::class ), $this->container->get( WooCommerceRateMapper::class ) ) );
		$this->container->register( LocationSearchService::class, fn(): LocationSearchService => new LocationSearchService( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( LocationImportService::class, fn(): LocationImportService => new LocationImportService( $this->container->get( LocationRepository::class ) ) );
		$this->container->register( GarChangesService::class, fn(): GarChangesService => new GarChangesService() );
		$this->container->register( YearGenerator::class, fn(): YearGenerator => new YearGenerator() );
		$this->container->register( TimezoneService::class, fn(): TimezoneService => new TimezoneService() );
		$this->container->register( DeliveryDateFormatter::class, fn(): DeliveryDateFormatter => new DeliveryDateFormatter() );
		$this->container->register(
			CalendarService::class,
			fn(): CalendarService => new CalendarService(
				$this->container->get( CalendarRepository::class ),
				$this->container->get( YearGenerator::class ),
				$this->container->get( SettingsRepository::class ),
				$this->container->get( TimezoneService::class )
			)
		);
		$this->container->register(
			DeliveryDateCalculator::class,
			fn(): DeliveryDateCalculator => new DeliveryDateCalculator(
				$this->container->get( CalendarService::class ),
				$this->container->get( TimezoneService::class ),
				$this->container->get( DeliveryDateFormatter::class )
			)
		);
		$this->container->register(
			CalendarScheduler::class,
			fn(): CalendarScheduler => new CalendarScheduler(
				$this->container->get( ActionScheduler::class ),
				$this->container->get( CalendarService::class ),
				$this->container->get( TimezoneService::class )
			)
		);
		$this->container->register( RequirementsChecker::class, fn(): RequirementsChecker => new RequirementsChecker( $this->environment ) );
		$this->container->register( HPOSCompatibility::class, fn(): HPOSCompatibility => new HPOSCompatibility( $this->environment ) );
		$this->container->register(
			AdminNotices::class,
			fn(): AdminNotices => new AdminNotices(
				$this->container->get( RequirementsChecker::class ),
				$this->container->get( CalendarService::class )
			)
		);
		$this->container->register(
			AdminMenu::class,
			fn(): AdminMenu => new AdminMenu(
				$this->environment,
				$this->container->get( FeatureFlags::class ),
				$this->container->get( RequirementsChecker::class )
			)
		);
		$this->container->register(
			CalendarAdminPage::class,
			fn(): CalendarAdminPage => new CalendarAdminPage(
				$this->environment,
				$this->container->get( CalendarService::class ),
				$this->container->get( CalendarRepository::class ),
				$this->container->get( YearGenerator::class )
			)
		);
		$this->container->register(
			LocationsAdminPage::class,
			fn(): LocationsAdminPage => new LocationsAdminPage(
				$this->environment,
				$this->container->get( LocationRepository::class ),
				$this->container->get( LocationSearchService::class ),
				$this->container->get( LocationImportService::class )
			)
		);
		$this->container->register(
			RulesAdminPage::class,
			fn(): RulesAdminPage => new RulesAdminPage(
				$this->environment,
				$this->container->get( RuleRepository::class ),
				$this->container->get( RuleSimulator::class )
			)
		);
		$this->container->register(
			CheckoutSimulationPage::class,
			fn(): CheckoutSimulationPage => new CheckoutSimulationPage(
				$this->environment,
				$this->container->get( CheckoutOrchestrator::class )
			)
		);
	}

	private function register_hooks(): void {
		$this->container->get( HPOSCompatibility::class )->register();

		add_action( 'plugins_loaded', array( $this, 'boot_modules' ), 20 );
		register_activation_hook( $this->environment->plugin_file(), array( $this, 'activate' ) );

		if ( is_admin() ) {
			$this->container->get( AdminNotices::class )->register();
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( CalendarAdminPage::class )->register();
			$this->container->get( LocationsAdminPage::class )->register();
			$this->container->get( RulesAdminPage::class )->register();
			$this->container->get( CheckoutSimulationPage::class )->register();
		}
	}

	public function boot_modules(): void {
		$this->container->get( MigrationManager::class )->run();
		$this->container->get( CalendarService::class )->ensure_initial_years();
		$this->container->get( ActionScheduler::class );
		$this->container->get( GarChangesService::class );
		$this->container->get( CalendarScheduler::class )->register();
	}

	public function activate(): void {
		$this->container->get( MigrationManager::class )->run();
		$this->container->get( CalendarService::class )->ensure_initial_years();
	}
}
