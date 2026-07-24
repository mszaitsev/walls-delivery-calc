<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

function cdek_eaeu_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cdek_eaeu_source( string $path ): string {
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . ltrim( $path, '/' ) );
	if ( false === $source ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}

	return $source;
}

$settings = cdek_eaeu_source( 'src/Carriers/Cdek/CdekSettings.php' );
$carrier = cdek_eaeu_source( 'src/Carriers/Runtime/CdekCarrier.php' );
$manager = cdek_eaeu_source( 'src/DeliveryServices/DeliveryServiceManager.php' );
$admin = cdek_eaeu_source( 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$migration = cdek_eaeu_source( 'database/migrations/0042_seed_cdek_eaeu_countries.php' );
$resolver = cdek_eaeu_source( 'src/Carriers/Cdek/CdekLocationResolver.php' );
$points = cdek_eaeu_source( 'src/Pickup/Cdek/CdekDeliveryPointService.php' );
$checkout_js = cdek_eaeu_source( 'assets/frontend/pickup-map/wdc-pickup-checkout.js' );
$dpd_import = cdek_eaeu_source( 'src/Carriers/Dpd/Geography/DpdGeographyImportService.php' );
$location = cdek_eaeu_source( 'src/Locations/ValueObjects/Location.php' );
$draft = cdek_eaeu_source( 'src/Shipments/Application/OrderShipmentDraftFactory.php' );
$builder = cdek_eaeu_source( 'src/Shipments/Cdek/CdekCreateRequestBuilder.php' );
$adapter = cdek_eaeu_source( 'src/Shipments/Cdek/CdekShipmentAdapter.php' );
$api = cdek_eaeu_source( 'src/Carriers/Cdek/Api/CdekApiClient.php' );
$modal = cdek_eaeu_source( 'src/Shipments/Cdek/CdekShipmentModalExtension.php' );
$js = cdek_eaeu_source( 'assets/admin/shipments/extensions/cdek.js' );
$shipment_picker_js = cdek_eaeu_source( 'assets/admin/shipments/shipment-picker.js' );

cdek_eaeu_assert( str_contains( $settings, "SUPPORTED_COUNTRIES = array( 'RU', 'AM', 'BY', 'KZ', 'KG' )" ), 'CDEK supported country universe must be RU/AM/BY/KZ/KG.' );
cdek_eaeu_assert( str_contains( $carrier, 'supports_international: true' ) && str_contains( $carrier, 'CdekSettings::SUPPORTED_COUNTRIES' ), 'CDEK carrier must support international through one existing carrier.' );
cdek_eaeu_assert( ! str_contains( $carrier . $manager . $admin, 'cdek_international' ) && ! str_contains( $carrier . $manager . $admin, 'CdekInternational' ), 'CDEK EAEU must not introduce separate carrier/service keys.' );
cdek_eaeu_assert( str_contains( $manager, 'cdek_service_exists' ) && str_contains( $manager, 'SUPPORTED_COUNTRIES' ) && ! str_contains( $manager, 'replace_countries( (int) $cdek->id, array( \'RU\' )' ), 'ensure_builtin_services must seed CDEK countries only on fresh creation.' );
cdek_eaeu_assert( str_contains( $migration, "service_key = %s" ) && str_contains( $migration, "array( 'RU' )" ) && str_contains( $migration, "array( 'RU', 'AM', 'BY', 'KZ', 'KG' )" ), 'Migration must seed existing empty/RU-only CDEK countries once.' );
cdek_eaeu_assert( str_contains( $admin, 'name="countries[]"' ) && str_contains( $admin, 'render_cdek_country_checkboxes' ) && str_contains( $admin, 'array_intersect' ), 'CDEK admin must use generic countries[] save flow with allowlisted checkboxes.' );

cdek_eaeu_assert( str_contains( $dpd_import, 'process_foreign_row' ) && str_contains( $dpd_import, "array( 'AM', 'BY', 'KZ', 'KG' )" ) && str_contains( $dpd_import, 'upsert_candidate' ) && ! str_contains( $dpd_import, 'save_dpd_city_id( $saved_id' ), 'DPD geography import must create foreign locations and stage DPD city mappings for finalization.' );
cdek_eaeu_assert( str_contains( $location, "'RU' === \$country_code && 0 >= \$this->gar_object_id" ) && str_contains( $location, "'RU' === \$country_code && '' === trim( \$this->fias_id )" ), 'Location validation must keep GAR/FIAS requirements scoped to RU.' );

cdek_eaeu_assert( str_contains( $resolver, "'country_codes' => \$country" ) && str_contains( $resolver, '$this->settings->environment()' ) && str_contains( $resolver, "'ambiguous'" ) && ! str_contains( $resolver, "'city_region'" ), 'CDEK resolver must be country-aware and not use free-text region as API filter.' );
cdek_eaeu_assert( str_contains( $points, "'country_code' => \$country_code" ) && str_contains( $points, "'is_handout'" ) && str_contains( $carrier, 'has_handout_delivery_point' ), 'CDEK pickup quotes must require country-aware handout points.' );
cdek_eaeu_assert( str_contains( $checkout_js, 'normalizeText(context.country_code || \'\')' ) && str_contains( $checkout_js, 'country_code: point.country_code || snapshot.country_code' ) && str_contains( $checkout_js, 'cdek_city_code: point.cdek_city_code' ) && str_contains( $checkout_js, 'is_handout: point.is_handout !== undefined' ) && str_contains( $checkout_js, 'Object.prototype.hasOwnProperty.call(response, \'activePickupCountryCode\')' ), 'Frontend pickup map must carry country/cdek city/handout identity and refresh active pickup country from REST responses.' );
cdek_eaeu_assert( str_contains( $checkout_js, 'pointCountry && contextCountry && pointCountry !== contextCountry' ) && str_contains( $checkout_js, 'matchedBy = \'\';' ), 'Frontend pickup matching must reject known country mismatch before location shortcuts.' );
cdek_eaeu_assert( str_contains( $shipment_picker_js, 'cdekCityCode' ) && str_contains( $shipment_picker_js, "data.append('city_code', cdekCityCode)" ) && str_contains( $shipment_picker_js, "data.append('cdek_city_code', cdekCityCode)" ) && str_contains( $shipment_picker_js, 'recipient_location_city_id: cdekCityCode' ) && str_contains( $shipment_picker_js, 'pickup_point_cdek_city_code: cdekCityCode' ), 'Admin shipment pickup picker must pass and update canonical CDEK city code fields.' );

cdek_eaeu_assert( str_contains( $draft, 'cdek_recipient_document_from_admin_data' ) && ! str_contains( $draft, "'cdek_recipient_document' =>" ), 'Recipient document must stay out of shipment meta.' );
cdek_eaeu_assert( str_contains( $builder, "\$recipient['tin']" ) && str_contains( $builder, "'KZ', 'KG'" ) && str_contains( $builder, "\$recipient['passport_number']" ) && str_contains( $builder, "'AM', 'BY'" ) && ! str_contains( $builder, 'Fill CDEK recipient document for Kazakhstan' ), 'CDEK builder must keep recipient document optional and map KZ/KG to tin, AM/BY to passport_number.' );
cdek_eaeu_assert( str_contains( $adapter, 'sanitize_request_snapshot' ) && str_contains( $adapter, 'sanitize_recipient' ) && ! str_contains( $adapter, "'tin' =>" ), 'CDEK snapshots must not include raw recipient tin.' );
cdek_eaeu_assert( str_contains( $api, "'tin'" ) && str_contains( $api, "'passport_number'" ) && str_contains( $api, "'cdek_recipient_document'" ), 'CDEK API diagnostics must redact recipient document fields.' );
cdek_eaeu_assert( str_contains( $modal, 'data-wdc-cdek-recipient-document' ) && str_contains( $js, 'updateCdekRecipientDocumentUi' ) && str_contains( $js, 'descriptions' ) && str_contains( $js, 'KZ:' ) && str_contains( $js, 'AM:' ) && str_contains( $js, 'input.required = false' ) && ! str_contains( $js, 'required = country' ) && ! str_contains( $js, 'localStorage' ) && ! str_contains( $js, 'sessionStorage' ), 'CDEK modal document field must be CDEK-only, optional, country-aware, and page-memory only.' );

echo "CDEK EAEU smoke OK\n";
