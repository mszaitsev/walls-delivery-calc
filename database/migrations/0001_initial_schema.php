<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationManualOverrideV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsTable;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	if ( ! function_exists( 'dbDelta' ) ) {
		throw new RuntimeException( 'WordPress dbDelta function is unavailable.' );
	}

	$charset = $wpdb->get_charset_collate();
	$table = static fn( string $name ): string => $wpdb->prefix . $name;
	$schemas = array(
		"CREATE TABLE {$table('wdc_calendar_days')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			calendar_type varchar(50) NOT NULL,
			calendar_date date NOT NULL,
			is_working tinyint(1) NOT NULL DEFAULT 0,
			reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY calendar_type_date (calendar_type,calendar_date),
			KEY calendar_date (calendar_date)
		) {$charset};",
		"CREATE TABLE {$table('wdc_regions')} (
			region_code char(2) NOT NULL,
			region_name varchar(120) NOT NULL,
			region_type varchar(30) NULL,
			region_fias_id char(36) NULL,
			region_kladr_id varchar(19) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (region_code),
			KEY region_fias_id (region_fias_id),
			KEY region_kladr_id (region_kladr_id)
		) {$charset};",
		"CREATE TABLE {$table('wdc_gar_places_stage')} (
			region_code varchar(2) NULL,
			region_name varchar(120) NULL,
			region_type varchar(30) NULL,
			region_fias_id char(36) NULL,
			region_kladr_id varchar(19) NULL,
			district_name varchar(160) NULL,
			district_type varchar(30) NULL,
			district_fias_id char(36) NULL,
			district_kladr_id varchar(19) NULL,
			district_gar_object_id bigint(20) unsigned NULL,
			district_level smallint NULL,
			city_name varchar(120) NULL,
			city_type varchar(30) NULL,
			city_fias_id char(36) NULL,
			city_kladr_id varchar(19) NULL,
			place_name varchar(160) NULL,
			place_type varchar(30) NULL,
			place_level smallint NULL,
			display_name varchar(400) NULL,
			fias_id char(36) NULL,
			gar_object_id bigint(20) unsigned NULL,
			kladr_id varchar(19) NULL,
			okato varchar(20) NULL,
			oktmo varchar(20) NULL,
			postal_code varchar(10) NULL,
			KEY gar_object_id (gar_object_id),
			KEY fias_id (fias_id),
			KEY region_code (region_code),
			KEY kladr_id (kladr_id),
			KEY district_fias_id (district_fias_id),
			KEY district_gar_object_id (district_gar_object_id)
		) {$charset};",
		"CREATE TABLE {$table('wdc_locations')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gar_object_id bigint(20) unsigned NULL,
			fias_id char(36) NULL,
			kladr_id varchar(19) NULL,
			gar_id varchar(64) NOT NULL DEFAULT '',
			country_code varchar(8) NOT NULL DEFAULT 'RU',
			region_name varchar(255) NOT NULL DEFAULT '',
			region_code char(2) NOT NULL,
			region_type varchar(30) NOT NULL DEFAULT '',
			district_name varchar(160) NOT NULL DEFAULT '',
			district_type varchar(30) NOT NULL DEFAULT '',
			district_fias_id char(36) NULL,
			district_kladr_id varchar(19) NULL,
			district_gar_object_id bigint(20) unsigned NULL,
			district_level smallint unsigned NULL,
			city_name varchar(120) NULL,
			city_type varchar(30) NULL,
			city_fias_id char(36) NULL,
			city_kladr_id varchar(19) NULL,
			settlement_name varchar(255) NOT NULL DEFAULT '',
			settlement_type varchar(64) NOT NULL DEFAULT '',
			place_name varchar(160) NOT NULL,
			place_type varchar(30) NULL,
			place_level smallint unsigned NOT NULL,
			display_name varchar(400) NOT NULL,
			searchable_text longtext NOT NULL,
			okato varchar(20) NULL,
			oktmo varchar(20) NULL,
			postal_code varchar(32) NOT NULL DEFAULT '',
			russianpost_courier_calc_postal_code varchar(32) NOT NULL DEFAULT '',
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_gar_object_id (gar_object_id),
			UNIQUE KEY ux_fias_id (fias_id),
			KEY ix_kladr_id (kladr_id),
			KEY ix_region_code (region_code),
			KEY ix_region_type (region_type),
			KEY ix_region_place (region_code,place_name),
			KEY ix_district_fias_id (district_fias_id),
			KEY ix_district_gar_object_id (district_gar_object_id),
			KEY ix_region_district_place (region_code,district_name,place_name),
			KEY ix_city_fias_id (city_fias_id),
			KEY ix_city_place (city_name,place_name),
			KEY ix_active (active),
			KEY idx_active_country_code (active,country_code),
			KEY country_code (country_code),
			KEY region_name (region_name),
			KEY city_name (city_name),
			KEY settlement_name (settlement_name),
			KEY postal_code (postal_code),
			KEY postal_code_rp_courier_calc (postal_code,russianpost_courier_calc_postal_code)
		) {$charset};",
		"CREATE TABLE {$table('wdc_rules')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL DEFAULT '',
			enabled tinyint(1) NOT NULL DEFAULT 1,
			priority int NOT NULL DEFAULT 100,
			target_type varchar(64) NOT NULL DEFAULT '',
			target_value varchar(255) NOT NULL DEFAULT '',
			action_type varchar(64) NOT NULL DEFAULT '',
			operation_type varchar(64) NOT NULL DEFAULT '',
			operation_value decimal(18,4) NOT NULL DEFAULT 0.0000,
			operation_base varchar(64) NOT NULL DEFAULT '',
			operation_text longtext NULL,
			promo_shipping tinyint(1) NOT NULL DEFAULT 0,
			stop_processing tinyint(1) NOT NULL DEFAULT 0,
			condition_group_logic longtext NULL,
			condition_group_expression varchar(80) NOT NULL DEFAULT 'condition_1_or_2_or_3',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY enabled (enabled),
			KEY priority (priority),
			KEY target_type (target_type),
			KEY promo_shipping (promo_shipping)
		) {$charset};",
		"CREATE TABLE {$table('wdc_rule_conditions')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			condition_group int NOT NULL DEFAULT 1,
			condition_type varchar(64) NOT NULL DEFAULT '',
			operator varchar(64) NOT NULL DEFAULT '',
			value_text text NOT NULL,
			value_number decimal(18,4) NULL,
			value_json longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY condition_type (condition_type),
			KEY condition_group (condition_group)
		) {$charset};",
		"CREATE TABLE {$table('wdc_pickup_points')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			carrier_key varchar(64) NOT NULL DEFAULT '',
			point_code varchar(128) NOT NULL DEFAULT '',
			point_type varchar(64) NOT NULL DEFAULT '',
			country_code varchar(8) NOT NULL DEFAULT '',
			region_name varchar(255) NOT NULL DEFAULT '',
			city_name varchar(255) NOT NULL DEFAULT '',
			address text NOT NULL,
			postcode varchar(32) NOT NULL DEFAULT '',
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			work_time text NOT NULL,
			comment text NOT NULL,
			extra_cost_kopecks bigint(20) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			raw_reference longtext NULL,
			updated_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY carrier_point (carrier_key,point_code),
			KEY city_name (city_name),
			KEY region_name (region_name),
			KEY active (active)
		) {$charset};",
		"CREATE TABLE {$table('wdc_russian_post_country_mappings')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_country_code varchar(2) NOT NULL,
			wc_country_name varchar(190) NOT NULL DEFAULT '',
			rp_country_id varchar(50) NOT NULL DEFAULT '',
			rp_country_name varchar(190) NOT NULL DEFAULT '',
			rp_iso2 varchar(2) NOT NULL DEFAULT '',
			has_parcel tinyint(1) NOT NULL DEFAULT 0,
			parcel_block tinyint(1) NOT NULL DEFAULT 0,
			api_available tinyint(1) NOT NULL DEFAULT 0,
			matched tinyint(1) NOT NULL DEFAULT 0,
			match_source varchar(20) NOT NULL DEFAULT 'none',
			manual_mode varchar(20) NOT NULL DEFAULT 'auto',
			effective_enabled tinyint(1) NOT NULL DEFAULT 0,
			last_checked_at datetime NULL,
			manual_comment varchar(255) NOT NULL DEFAULT '',
			raw_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_country_code (wc_country_code),
			KEY rp_country_id (rp_country_id),
			KEY rp_iso2 (rp_iso2),
			KEY matched (matched),
			KEY match_source (match_source),
			KEY effective_enabled (effective_enabled),
			KEY manual_mode (manual_mode)
		) {$charset};",
		"CREATE TABLE {$table('wdc_delivery_services')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_key varchar(120) NOT NULL,
			carrier_key varchar(120) NOT NULL DEFAULT '',
			service_type varchar(40) NOT NULL,
			title varchar(255) NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			availability_mode varchar(40) NOT NULL DEFAULT 'selected_countries',
			use_default_rules_when_no_service_rules tinyint(1) NOT NULL DEFAULT 1,
			round_up_to_ruble tinyint(1) NOT NULL DEFAULT 1,
			minimum_price_rub decimal(12,4) NOT NULL DEFAULT 1.0000,
			include_packaging_weight tinyint(1) NOT NULL DEFAULT 1,
			packaging_weight_mode varchar(30) NOT NULL DEFAULT 'total_weight',
			pickup_customer_comment text NULL,
			courier_customer_comment text NULL,
			sort_order int NOT NULL DEFAULT 100,
			deleted tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_key (service_key),
			KEY carrier_key (carrier_key),
			KEY enabled (enabled),
			KEY service_type (service_type),
			KEY deleted (deleted),
			KEY sort_order (sort_order)
		) {$charset};",
		"CREATE TABLE {$table('wdc_delivery_service_settings')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			setting_key varchar(120) NOT NULL,
			setting_value longtext NULL,
			value_format varchar(20) NOT NULL DEFAULT 'json',
			autoload tinyint(1) NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_setting (service_id,setting_key),
			KEY service_id (service_id),
			KEY setting_key (setting_key)
		) {$charset};",
		"CREATE TABLE {$table('wdc_delivery_service_countries')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			country_code varchar(2) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY service_country (service_id,country_code),
			KEY country_code (country_code)
		) {$charset};",
		"CREATE TABLE {$table('wdc_location_delivery_codes')} (
			location_id bigint(20) unsigned NOT NULL,
			dpd_city_id bigint(20) unsigned NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (location_id),
			KEY dpd_city_id (dpd_city_id)
		) {$charset};",
		"CREATE TABLE {$table('wdc_ozon_delivery_pickup_generations')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			state varchar(16) NOT NULL,
			phase varchar(16) NOT NULL DEFAULT 'discovery',
			job_id char(64) NOT NULL,
			lock_owner varchar(64) NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			progress_updated_at datetime NULL,
			cursor_value varchar(255) NULL,
			page_count int unsigned NOT NULL DEFAULT 0,
			downloaded_count int unsigned NOT NULL DEFAULT 0,
			discovery_page_count int unsigned NOT NULL DEFAULT 0,
			discovered_count int unsigned NOT NULL DEFAULT 0,
			discovery_completed_at datetime NULL,
			enrichment_processed_count int unsigned NOT NULL DEFAULT 0,
			accepted_count int unsigned NOT NULL DEFAULT 0,
			rejected_count int unsigned NOT NULL DEFAULT 0,
			duplicate_count int unsigned NOT NULL DEFAULT 0,
			conflict_count int unsigned NOT NULL DEFAULT 0,
			retry_count int unsigned NOT NULL DEFAULT 0,
			safe_error_code varchar(80) NULL,
			safe_error_message varchar(300) NULL,
			safe_error_operation varchar(120) NULL,
			safe_error_http_status smallint unsigned NULL,
			safe_error_retryable tinyint(1) NULL,
			failed_page int unsigned NULL,
			failed_cursor varchar(255) NULL,
			failed_after_ids int unsigned NULL,
			failed_attempt int unsigned NULL,
			failed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY state (state),
			KEY completed_at (completed_at)
		) {$charset};",
		"CREATE TABLE {$table('wdc_ozon_delivery_pickup_points')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			generation_id bigint(20) unsigned NOT NULL,
			point_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			point_number varchar(100) NULL,
			type varchar(32) NOT NULL,
			full_address text NOT NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			schedule text NOT NULL,
			is_active tinyint(1) NOT NULL,
			is_bulky tinyint(1) NOT NULL,
			storage_period_days int NULL,
			fitting_rooms_count int NULL,
			min_weight_g int NULL,
			max_weight_g int NULL,
			max_width_mm int NULL,
			max_length_mm int NULL,
			max_height_mm int NULL,
			fingerprint char(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY generation_point (generation_id,point_id),
			KEY generation_id (generation_id),
			KEY active_lookup (generation_id,is_active,type),
			KEY active_geo_lookup (generation_id,is_active,latitude,longitude)
		) {$charset};",
		"CREATE TABLE {$table('wdc_ozon_delivery_pickup_ids')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			generation_id bigint(20) unsigned NOT NULL,
			point_id bigint(20) unsigned NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			reject_code varchar(40) NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY generation_point (generation_id,point_id),
			KEY generation_status_id (generation_id,status,id),
			KEY generation_id (generation_id)
		) {$charset};",
		"CREATE TABLE {$table('wdc_manual_delivery_regions')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			country_code varchar(2) NOT NULL DEFAULT 'RU',
			region_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_region_country (service_id,country_code,region_name),
			KEY service_id (service_id),
			KEY region_name (region_name),
			KEY country_region (country_code,region_name)
		) {$charset};",
		"CREATE TABLE {$table('wdc_manual_delivery_locations')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			country_code varchar(2) NOT NULL DEFAULT 'RU',
			location_name varchar(255) NOT NULL DEFAULT '',
			region_name varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_location_country (service_id,country_code,location_name,region_name),
			KEY service_id (service_id),
			KEY region_name (region_name),
			KEY location_name (location_name),
			KEY country_location (country_code,location_name,region_name)
		) {$charset};",
		"CREATE TABLE {$table('wdc_manual_delivery_weight_ranges')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			from_weight_g int unsigned NOT NULL DEFAULT 0,
			to_weight_g int unsigned NOT NULL DEFAULT 0,
			price_kopecks bigint(20) unsigned NOT NULL DEFAULT 0,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_weight_range (service_id,from_weight_g,to_weight_g),
			KEY service_id (service_id),
			KEY weight_lookup (service_id,from_weight_g,to_weight_g)
		) {$charset};",
		"CREATE TABLE {$table('wdc_manual_delivery_pickup_points')} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			code varchar(120) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			country_code varchar(2) NOT NULL,
			region_name varchar(255) NOT NULL,
			location_name varchar(255) NOT NULL,
			address text NOT NULL,
			postcode varchar(32) NOT NULL DEFAULT '',
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			work_time text NOT NULL,
			comment text NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_pickup_service_code (service_id,code),
			KEY service_id (service_id),
			KEY active_lookup (service_id,active),
			KEY locality_lookup (service_id,active,country_code,region_name(120),location_name(120))
		) {$charset};",
	);

	foreach ( $schemas as $schema ) {
		dbDelta( $schema );
	}

	( new RussianPostPickupPointRepository() )->create_schema_if_needed();
	( new CdekTariffRepository() )->create_schema_if_needed();
	( new DpdPickupPointRepository() )->create_schema_if_needed();
	( new YandexDeliveryPickupPointV2Repository() )->create_schema_if_needed();
	( new YandexDeliveryGeoV2Repository() )->create_schema_if_needed();
	( new YandexLocationMappingV2Repository() )->create_schema_if_needed();
	( new YandexRegionMappingV2Repository() )->create_schema_if_needed();
	( new YandexLocationManualOverrideV2Repository() )->create_schema_if_needed();
	( new JetLogisticGeographyRepository() )->create_schema();
	( new JetLogisticGeographyOverrideRepository() )->create_schema();
	$jet_statuses = new JetLogisticStatusMappingRepository();
	$jet_statuses->create_schema();
	$jet_statuses->ensure_default_mappings();
	( new PekLocationMappingRepository() )->install_schema();
	( new PekTerminalRepository() )->install_schema();
	dbDelta( ( new ShipmentCostAnalyticsTable() )->schema() );
};
