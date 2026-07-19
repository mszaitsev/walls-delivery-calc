<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-js-bundle-source.php';

function shipment_admin_js_structure_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

function shipment_admin_js_is_identifier_char( string $char ): bool {
	return '' !== $char && 1 === preg_match( '/[A-Za-z0-9_$]/', $char );
}

function shipment_admin_js_statement_end( string $source, int $offset ): int {
	$length        = strlen( $source );
	$quote         = '';
	$escape        = false;
	$line_comment  = false;
	$block_comment = false;
	$brace_depth   = 0;
	$paren_depth   = 0;
	$bracket_depth = 0;

	for ( $i = $offset; $i < $length; $i++ ) {
		$char = $source[ $i ];
		$next = $source[ $i + 1 ] ?? '';

		if ( $line_comment ) {
			if ( "\n" === $char ) {
				$line_comment = false;
			}
			continue;
		}

		if ( $block_comment ) {
			if ( '*' === $char && '/' === $next ) {
				$block_comment = false;
				$i++;
			}
			continue;
		}

		if ( '' !== $quote ) {
			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escape = true;
				continue;
			}
			if ( $char === $quote ) {
				$quote = '';
			}
			continue;
		}

		if ( '/' === $char && '/' === $next ) {
			$line_comment = true;
			$i++;
			continue;
		}
		if ( '/' === $char && '*' === $next ) {
			$block_comment = true;
			$i++;
			continue;
		}
		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$quote = $char;
			continue;
		}

		if ( '{' === $char ) {
			$brace_depth++;
			continue;
		}
		if ( '}' === $char ) {
			$brace_depth = max( 0, $brace_depth - 1 );
			continue;
		}
		if ( '(' === $char ) {
			$paren_depth++;
			continue;
		}
		if ( ')' === $char ) {
			$paren_depth = max( 0, $paren_depth - 1 );
			continue;
		}
		if ( '[' === $char ) {
			$bracket_depth++;
			continue;
		}
		if ( ']' === $char ) {
			$bracket_depth = max( 0, $bracket_depth - 1 );
			continue;
		}
		if ( ';' === $char && 0 === $brace_depth && 0 === $paren_depth && 0 === $bracket_depth ) {
			return $i;
		}
	}

	return $length;
}

function shipment_admin_js_split_declarations( string $statement ): array {
	$parts         = array();
	$start         = 0;
	$length        = strlen( $statement );
	$quote         = '';
	$escape        = false;
	$line_comment  = false;
	$block_comment = false;
	$brace_depth   = 0;
	$paren_depth   = 0;
	$bracket_depth = 0;

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $statement[ $i ];
		$next = $statement[ $i + 1 ] ?? '';

		if ( $line_comment ) {
			if ( "\n" === $char ) {
				$line_comment = false;
			}
			continue;
		}
		if ( $block_comment ) {
			if ( '*' === $char && '/' === $next ) {
				$block_comment = false;
				$i++;
			}
			continue;
		}
		if ( '' !== $quote ) {
			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escape = true;
				continue;
			}
			if ( $char === $quote ) {
				$quote = '';
			}
			continue;
		}
		if ( '/' === $char && '/' === $next ) {
			$line_comment = true;
			$i++;
			continue;
		}
		if ( '/' === $char && '*' === $next ) {
			$block_comment = true;
			$i++;
			continue;
		}
		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$quote = $char;
			continue;
		}
		if ( '{' === $char ) {
			$brace_depth++;
			continue;
		}
		if ( '}' === $char ) {
			$brace_depth = max( 0, $brace_depth - 1 );
			continue;
		}
		if ( '(' === $char ) {
			$paren_depth++;
			continue;
		}
		if ( ')' === $char ) {
			$paren_depth = max( 0, $paren_depth - 1 );
			continue;
		}
		if ( '[' === $char ) {
			$bracket_depth++;
			continue;
		}
		if ( ']' === $char ) {
			$bracket_depth = max( 0, $bracket_depth - 1 );
			continue;
		}
		if ( ',' === $char && 0 === $brace_depth && 0 === $paren_depth && 0 === $bracket_depth ) {
			$parts[] = substr( $statement, $start, $i - $start );
			$start   = $i + 1;
		}
	}

	$parts[] = substr( $statement, $start );

	return $parts;
}

function shipment_admin_js_top_level_declarations( string $source ): array {
	$length        = strlen( $source );
	$quote         = '';
	$escape        = false;
	$line_comment  = false;
	$block_comment = false;
	$brace_depth   = 0;
	$result        = array(
		'functions' => array(),
		'lexical'   => array(),
	);

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $source[ $i ];
		$next = $source[ $i + 1 ] ?? '';

		if ( $line_comment ) {
			if ( "\n" === $char ) {
				$line_comment = false;
			}
			continue;
		}
		if ( $block_comment ) {
			if ( '*' === $char && '/' === $next ) {
				$block_comment = false;
				$i++;
			}
			continue;
		}
		if ( '' !== $quote ) {
			if ( $escape ) {
				$escape = false;
				continue;
			}
			if ( '\\' === $char ) {
				$escape = true;
				continue;
			}
			if ( $char === $quote ) {
				$quote = '';
			}
			continue;
		}
		if ( '/' === $char && '/' === $next ) {
			$line_comment = true;
			$i++;
			continue;
		}
		if ( '/' === $char && '*' === $next ) {
			$block_comment = true;
			$i++;
			continue;
		}
		if ( "'" === $char || '"' === $char || '`' === $char ) {
			$quote = $char;
			continue;
		}

		if ( 0 === $brace_depth ) {
			foreach ( array( 'const', 'let' ) as $keyword ) {
				$keyword_length = strlen( $keyword );
				if (
					substr( $source, $i, $keyword_length ) === $keyword
					&& ! shipment_admin_js_is_identifier_char( $source[ $i - 1 ] ?? '' )
					&& ! shipment_admin_js_is_identifier_char( $source[ $i + $keyword_length ] ?? '' )
				) {
					$end       = shipment_admin_js_statement_end( $source, $i + $keyword_length );
					$statement = substr( $source, $i + $keyword_length, $end - ( $i + $keyword_length ) );
					foreach ( shipment_admin_js_split_declarations( $statement ) as $part ) {
						if ( 1 === preg_match( '/^\\s*([A-Za-z_$][A-Za-z0-9_$]*)\\b/', $part, $match ) ) {
							$result['lexical'][ $match[1] ][] = array(
								'kind'   => $keyword,
								'offset' => $i,
							);
						} elseif ( 1 === preg_match( '/^\\s*[\\[{]/', $part ) ) {
							shipment_admin_js_structure_assert( false, 'Admin JS top-level lexical scanner does not support destructuring declarations.' );
						}
					}
					$i = $end;
					continue 2;
				}
			}

			$keyword = 'function';
			if (
				substr( $source, $i, strlen( $keyword ) ) === $keyword
				&& ! shipment_admin_js_is_identifier_char( $source[ $i - 1 ] ?? '' )
				&& ! shipment_admin_js_is_identifier_char( $source[ $i + strlen( $keyword ) ] ?? '' )
				&& 1 === preg_match( '/^function\\s+([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\(/', substr( $source, $i ), $match )
			) {
				$result['functions'][ $match[1] ][] = 'function';
			}
		}

		if ( '{' === $char ) {
			$brace_depth++;
			continue;
		}
		if ( '}' === $char ) {
			$brace_depth = max( 0, $brace_depth - 1 );
			continue;
		}
	}

	return $result;
}

function shipment_admin_js_line_for_offset( string $source, int $offset ): int {
	return substr_count( substr( $source, 0, max( 0, $offset ) ), "\n" ) + 1;
}

function shipment_admin_js_lexical_owners( string $source, string $owner ): array {
	$declarations = shipment_admin_js_top_level_declarations( $source );
	$bindings     = array();
	foreach ( $declarations['lexical'] as $name => $occurrences ) {
		foreach ( $occurrences as $occurrence ) {
			$kind                 = (string) ( $occurrence['kind'] ?? 'const' );
			$line                 = shipment_admin_js_line_for_offset( $source, (int) ( $occurrence['offset'] ?? 0 ) );
			$bindings[ $name ][] = $owner . ':' . $line . ' (' . $kind . ')';
		}
	}
	return $bindings;
}

function shipment_admin_js_duplicate_bindings( array $bindings ): array {
	return array_filter(
		$bindings,
		static fn ( array $owners ): bool => count( $owners ) > 1
	);
}

function shipment_admin_js_duplicate_binding_message( array $bindings ): string {
	$lines = array();
	foreach ( $bindings as $name => $owners ) {
		if ( count( $owners ) > 1 ) {
			$lines[] = $name . ': ' . implode( ', ', $owners );
		}
	}
	return implode( '; ', $lines );
}

function shipment_admin_js_scanner_self_test(): void {
	$duplicate_const = shipment_admin_js_duplicate_bindings( shipment_admin_js_lexical_owners( "const duplicate = 1;\nconst duplicate = 2;\n", 'fixture-const.js' ) );
	shipment_admin_js_structure_assert( isset( $duplicate_const['duplicate'] ) && 2 === count( $duplicate_const['duplicate'] ), 'Scanner self-test must detect duplicate top-level const declarations.' );

	$duplicate_let = shipment_admin_js_duplicate_bindings( shipment_admin_js_lexical_owners( "let duplicate = 1;\nlet duplicate = 2;\n", 'fixture-let.js' ) );
	shipment_admin_js_structure_assert( isset( $duplicate_let['duplicate'] ) && 2 === count( $duplicate_let['duplicate'] ), 'Scanner self-test must detect duplicate top-level let declarations.' );

	$const_let = shipment_admin_js_duplicate_bindings( shipment_admin_js_lexical_owners( "const collision = 1;\nlet collision = 2;\n", 'fixture-const-let.js' ) );
	shipment_admin_js_structure_assert( isset( $const_let['collision'] ) && 2 === count( $const_let['collision'] ), 'Scanner self-test must detect const/let top-level collisions.' );

	$function_collision_declarations = shipment_admin_js_top_level_declarations( "function collision() {}\nconst collision = 1;\n" );
	shipment_admin_js_structure_assert( isset( $function_collision_declarations['functions']['collision'], $function_collision_declarations['lexical']['collision'] ), 'Scanner self-test must detect function/lexical top-level collisions.' );

	$local_declarations = shipment_admin_js_top_level_declarations( "function one() {\n    const local = 1;\n}\n\nfunction two() {\n    const local = 2;\n}\n" );
	shipment_admin_js_structure_assert( ! isset( $local_declarations['lexical']['local'] ), 'Scanner self-test must ignore local const declarations.' );

	$multi_declarations = shipment_admin_js_top_level_declarations( "const a = 1,\n      b = 2;\n" );
	shipment_admin_js_structure_assert( isset( $multi_declarations['lexical']['a'], $multi_declarations['lexical']['b'] ), 'Scanner self-test must detect comma-separated top-level declarations.' );

	$comment_declarations = shipment_admin_js_top_level_declarations( "// const fake = 1\n\n/*\nconst fake2 = 2;\n*/\n\nconst real = 1;\n" );
	shipment_admin_js_structure_assert( isset( $comment_declarations['lexical']['real'] ) && ! isset( $comment_declarations['lexical']['fake'], $comment_declarations['lexical']['fake2'] ), 'Scanner self-test must ignore comments.' );

	$string_declarations = shipment_admin_js_top_level_declarations( "const text = \"const fake\";\n\nconst real = 1;\n" );
	shipment_admin_js_structure_assert( isset( $string_declarations['lexical']['text'], $string_declarations['lexical']['real'] ) && ! isset( $string_declarations['lexical']['fake'] ), 'Scanner self-test must ignore strings.' );
}

shipment_admin_js_scanner_self_test();

$root = dirname( __DIR__, 2 );
foreach ( array(
	'docs/README.md',
	'docs/architecture/plugin-architecture.md',
	'docs/architecture/dependency-injection.md',
	'docs/architecture/shipment-framework.md',
	'docs/development/chat-start.md',
	'docs/development/codex-prompt-template.md',
	'docs/development/new-carrier-guide.md',
	'docs/development/development-workflow.md',
	'docs/development/testing-and-regression.md',
	'docs/development/coding-rules.md',
	'docs/reference/walls-delivery-calc-tech-spec.md',
) as $canonical_doc ) {
	shipment_admin_js_structure_assert( is_file( $root . '/' . $canonical_doc ), 'Canonical documentation path must exist: ' . $canonical_doc );
}

$files = array(
	'bootstrap'    => $root . '/assets/admin/shipments-admin.js',
	'core'         => $root . '/assets/admin/shipments/shipment-core.js',
	'preview'      => $root . '/assets/admin/shipments/shipment-preview.js',
	'status'       => $root . '/assets/admin/shipments/shipment-status.js',
	'polling'      => $root . '/assets/admin/shipments/shipment-polling.js',
	'allocation'   => $root . '/assets/admin/shipments/shipment-allocation.js',
	'picker'       => $root . '/assets/admin/shipments/shipment-picker.js',
	'cdek'         => $root . '/assets/admin/shipments/extensions/cdek.js',
	'dpd'          => $root . '/assets/admin/shipments/extensions/dpd.js',
	'russian_post' => $root . '/assets/admin/shipments/extensions/russian-post.js',
	'yandex'       => $root . '/assets/admin/shipments/extensions/yandex.js',
	'events'       => $root . '/assets/admin/shipments/shipment-events.js',
);

$source = array();
foreach ( $files as $key => $file ) {
	shipment_admin_js_structure_assert( is_file( $file ), 'Missing shipment admin JS module: ' . $key );
	$source[ $key ] = (string) file_get_contents( $file );
}

shipment_admin_js_structure_assert( str_contains( $source['bootstrap'], 'initializeShipmentAdmin()' ) && ! str_contains( $source['bootstrap'], 'data-wdc-' ) && ! str_contains( $source['bootstrap'], 'function renderShipmentStatus' ) && ! str_contains( $source['bootstrap'], 'function requestPreview' ), 'Bootstrap must only initialize the modular shipment admin runtime.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['core'] ), 'Core module must not contain carrier-specific logic.' );
shipment_admin_js_structure_assert( str_contains( $source['preview'], 'function requestPreview' ) && str_contains( $source['preview'], 'function updateCreateAvailability' ), 'Preview module must own preview requests and create availability refresh.' );
shipment_admin_js_structure_assert( str_contains( $source['status'], 'function renderShipmentStatus' ) && str_contains( $source['status'], 'function updateShipmentButtons' ) && ! str_contains( $source['status'], 'fetch(' ), 'Status module must render status/buttons without AJAX fetch calls.' );
shipment_admin_js_structure_assert( str_contains( $source['polling'], 'function requestShipmentStatus' ) && str_contains( $source['polling'], 'function startShipmentRegistrationPolling' ) && str_contains( $source['polling'], 'function requestShipmentCancel' ), 'Polling module must own status polling, registration polling and cancellation requests.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['polling'] ), 'Polling module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['status'] ), 'Status module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( str_contains( $source['allocation'], 'function splitShipmentItemRow' ) && str_contains( $source['allocation'], 'function addManualShipmentItemRow' ) && str_contains( $source['allocation'], 'function updateShipmentPlaceOptions' ), 'Allocation module must own places/items/split/manual rows.' );
$place_select_change_pos = strpos( $source['events'], "event.target.matches('[data-wdc-shipment-place-select]')" );
$carrier_change_hook_pos = strpos( $source['events'], "dispatchShipmentCarrierHook('handleChange', event)" );
shipment_admin_js_structure_assert( str_contains( $source['allocation'], 'function refreshShipmentItemsSummary' ) && false !== $place_select_change_pos && false !== $carrier_change_hook_pos && $place_select_change_pos < $carrier_change_hook_pos && str_contains( $source['events'], 'refreshShipmentItemsSummary(allocationForm)' ), 'Changing an item place select must refresh allocation summary from current shipment item rows before carrier change hooks.' );
shipment_admin_js_structure_assert( str_contains( $source['picker'], 'function createPickupPicker' ) && str_contains( $source['picker'], 'window.WDCPickupApi.addressSearch' ), 'Picker module must own pickup picker and shared address search.' );
shipment_admin_js_structure_assert( str_contains( $source['picker'], 'function pickupContext(form, contextOverride)' ) && substr_count( $source['picker'], 'pickupContext(' ) >= 5, 'Picker module must define pickupContext before shared picker/search helpers call it.' );
shipment_admin_js_structure_assert( str_contains( $source['picker'], 'if (contextOverride && typeof contextOverride === \'object\') return contextOverride;' ) && str_contains( $source['picker'], 'data-wdc-pickup-family' ) && str_contains( $source['picker'], 'data-wdc-pickup-location-id' ), 'Picker context resolver must use explicit carrier overrides and existing modal hidden fields instead of an undefined external helper.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'requestCdekBarcodeDownload' ) && str_contains( $source['cdek'], 'updateCdekDeliveryModeUi' ), 'CDEK extension must own CDEK UI hooks and barcode download.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'requestDpdDocumentsDownload' ) && str_contains( $source['dpd'], 'syncDpdAddressFields' ), 'DPD extension must own DPD UI, documents and address hooks.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'requestYandexLabelDownload' ) && str_contains( $source['yandex'], 'yandexSourceDropoffContext' ), 'Yandex extension must own Yandex label and source drop-off hooks.' );
shipment_admin_js_structure_assert( str_contains( $source['russian_post'], 'Russian Post' ), 'Russian Post extension module must exist even when current behavior is shared.' );
shipment_admin_js_structure_assert( str_contains( $source['events'], 'function initializeShipmentAdmin' ) && str_contains( $source['events'], 'document.addEventListener' ), 'Events module must own DOM event wiring for the modular runtime.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['events'] ), 'Events module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( str_contains( $source['core'], 'function dispatchShipmentCarrierHook' ) && str_contains( $source['core'], 'registerShipmentCarrierHooks' ), 'Core module must expose the small carrier hook registry.' );

shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'data-wdc-dpd-contact-choice' ) && str_contains( $source['dpd'], 'data-wdc-dpd-contact-remove' ) && str_contains( $source['dpd'], 'data-wdc-dpd-documents-download' ) && str_contains( $source['dpd'], 'data-wdc-dpd-date-pickup' ), 'DPD extension must own DPD DOM selectors.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'data-wdc-cdek-barcode-download' ), 'CDEK extension must own CDEK barcode selector.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'data-wdc-yandex-label-download' ) && str_contains( $source['yandex'], 'data-wdc-open-yandex-source-dropoff-picker' ) && str_contains( $source['yandex'], 'data-wdc-reset-yandex-source-dropoff' ), 'Yandex extension must own Yandex document/source selectors.' );

shipment_admin_js_structure_assert( str_contains( $source['events'], 'afterAddressNormalized' ) && ! str_contains( $source['events'], 'syncDpdAddressFields' ) && ! str_contains( $source['events'], 'syncYandexAddressFields' ) && ! str_contains( $source['events'], 'data-wdc-cdek-city-code' ), 'Events module must dispatch address normalization hooks without carrier post-processing.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'syncDpdAddressFields' ) && str_contains( $source['dpd'], 'afterAddressNormalized' ), 'DPD extension must own DPD address normalization hook.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'data-wdc-cdek-city-code' ) && str_contains( $source['cdek'], 'afterAddressNormalized' ), 'CDEK extension must own CDEK city-code address hook.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'syncYandexAddressFields' ) && str_contains( $source['yandex'], 'afterAddressNormalized' ), 'Yandex extension must own Yandex address normalization hook.' );

shipment_admin_js_structure_assert( str_contains( $source['polling'], 'function continueShipmentLifecycle' ) && str_contains( $source['polling'], 'function handleShipmentLifecycleResult' ) && str_contains( $source['polling'], 'submitRequired' ) && str_contains( $source['polling'], 'pollRequired' ) && str_contains( $source['polling'], 'continuation_token' ), 'Polling module must own the carrier-neutral lifecycle continuation client.' );
shipment_admin_js_structure_assert( ! str_contains( $source['polling'], 'submitDpdRegistration' ) && ! str_contains( $source['polling'], 'startDpdRegistrationPolling' ) && ! str_contains( $source['polling'], 'registration_attempt_id' ) && ! str_contains( $source['polling'], 'attempt_id' ) && ! str_contains( $source['polling'], 'poll_purpose' ) && ! str_contains( $source['polling'], 'mode=dpd' ), 'Polling module must not own DPD lifecycle wrappers or legacy lifecycle field names.' );
shipment_admin_js_structure_assert( ! str_contains( $source['events'], 'registration_attempt_id' ) && str_contains( $source['events'], 'handleShipmentLifecycleResult' ) && str_contains( $source['events'], 'lifecycle' ), 'Events module must route create responses through the neutral lifecycle contract.' );
shipment_admin_js_structure_assert( ! str_contains( $source['dpd'], 'submitDpdRegistration' ) && ! str_contains( $source['dpd'], 'startDpdRegistrationPolling' ) && ! str_contains( $source['dpd'], 'handleCreateResponse' ) && str_contains( $source['dpd'], 'dpd_places_summary' ) && str_contains( $source['dpd'], 'renderStatus' ), 'DPD extension must own DPD presentation but not lifecycle AJAX transport.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'cancellationPollingProgressMessage' ) && str_contains( $source['yandex'], 'cancellationPollingExhaustedMessage' ) && str_contains( $source['yandex'], 'function isCancellationPollingPending' ) && str_contains( $source['yandex'], 'function isCancellationConfirmed' ) && str_contains( $source['yandex'], 'function finishCancellationPollingToast' ) && str_contains( $source['yandex'], 'yandex_self_pickup_node_code' ) && str_contains( $source['yandex'], 'renderStatus' ) && str_contains( $source['yandex'], 'handlePollingStatus' ) && str_contains( $source['yandex'], 'handlePollingExhausted' ), 'Yandex extension must own cancellation polling and self-pickup status presentation.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], "const rawStatus = String(statusPayload.yandex_status || '').trim().toUpperCase();" ) && str_contains( $source['yandex'], 'payloadData.cancelled_and_removed === true' ) && ! str_contains( $source['yandex'], 'statusPayload.yandex_status || statusPayload.carrier_status_title' ), 'Yandex cancellation polling must not treat carrier_status_title or empty status as terminal success/failure.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], "finishCancellationPollingToast(context.box, 'Отправление Яндекс отменено.', 'success')" ) && str_contains( $source['yandex'], 'toast.hidden = true;' ) && str_contains( $source['yandex'], 'cancellationPollingToasts.delete(box);' ), 'Yandex cancellation success must finish and auto-hide the progress toast.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'function hasCancellationPollingToast' ) && str_contains( $source['yandex'], 'cancellationPollingToasts.has(box)' ) && str_contains( $source['yandex'], 'if (!isYandexPollingContext(context) && !hasCancellationPollingToast(context && context.box)) return false;' ), 'Yandex cancelled_and_removed hook must finish an existing cancellation toast even after resetShipmentUi removes status payload identity.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'function isYandexPollingContext' ) && str_contains( $source['yandex'], 'button.dataset.shipmentKey' ) && str_contains( $source['yandex'], "trim() === 'yandex_delivery'" ), 'Yandex extension must identify polling ownership through the canonical yandex_delivery shipment key.' );
foreach ( array( 'handlePollingStart', 'handlePollingStatus', 'handlePollingError', 'handlePollingExhausted' ) as $hook_name ) {
	shipment_admin_js_structure_assert( 1 === preg_match( '/' . preg_quote( $hook_name, '/' ) . ': function \\(context\\) \\{\\s*if \\(!isYandexPollingContext\\(context\\)\\) return false;/s', $source['yandex'] ), 'Yandex polling hook must start with carrier guard: ' . $hook_name );
}
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'handlePollingStop: function (context)' ) && str_contains( $source['yandex'], 'if (isYandexPollingContext(context))' ) && str_contains( $source['yandex'], 'cancellationPollingToasts.has(box)' ), 'Yandex polling stop hook must clear only Yandex-owned cancellation toast state.' );
shipment_admin_js_structure_assert( ! str_contains( $source['yandex'], "statusPayload.carrier_key !== 'yandex_delivery' && !isCancellationPollingPurpose" ), 'Yandex cancellation purpose must not be treated as carrier identity.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'startCdekPolling' ) && str_contains( $source['cdek'], 'handleDefaultRegistrationPolling' ), 'CDEK extension must own the CDEK default registration polling wrapper.' );
$legacy_document_payload_key = 'label_' . 'actions';
shipment_admin_js_structure_assert( str_contains( $source['status'], 'documentActions' ) && str_contains( $source['status'], 'document_actions' ) && str_contains( $source['status'], 'data-wdc-shipment-document-download' ) && ! str_contains( $source['status'], $legacy_document_payload_key ) && ! str_contains( $source['status'], 'labelActions' ) && ! str_contains( $source['status'], 'can_download_dpd_documents' ) && ! str_contains( $source['status'], 'can_download_yandex_label' ) && ! str_contains( $source['status'], 'data-wdc-cdek-barcode-download' ) && ! str_contains( $source['status'], 'data-wdc-dpd-documents-download' ) && ! str_contains( $source['status'], 'data-wdc-yandex-label-download' ), 'Status module must drive document visibility through normalized documentActions, canonical document_actions payload, and generic document selectors.' );

foreach ( array(
	'src/Shipments/Admin/OrderShipmentsMetabox.php',
	'src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php',
	'assets/admin/shipments/shipment-status.js',
) as $payload_file ) {
	$payload_source = (string) file_get_contents( $root . '/' . $payload_file );
	shipment_admin_js_structure_assert( str_contains( $payload_source, 'document_actions' ) && ! str_contains( $payload_source, $legacy_document_payload_key ), $payload_file . ' must use canonical document_actions payload key only.' );
}

$bundle_source = wdc_shipment_admin_js_bundle_source();
preg_match_all( '/\\bfunction\\s+([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\(/', $bundle_source, $function_matches );
$function_counts = array_count_values( $function_matches[1] ?? array() );
$duplicates = array_filter(
	$function_counts,
	static fn ( int $count ): bool => $count > 1
);
shipment_admin_js_structure_assert( array() === $duplicates, 'Admin JS bundle must not contain duplicate function declarations: ' . implode( ', ', array_keys( $duplicates ) ) );
shipment_admin_js_structure_assert( 0 === ( $function_counts['submitDpdRegistration'] ?? 0 ), 'submitDpdRegistration must be removed from the production admin JS bundle.' );
shipment_admin_js_structure_assert( 0 === ( $function_counts['startDpdRegistrationPolling'] ?? 0 ), 'startDpdRegistrationPolling must be removed from the production admin JS bundle.' );

$top_level_lexical_bindings = array();
$top_level_functions        = array();
foreach ( $files as $key => $file ) {
	$declarations = shipment_admin_js_top_level_declarations( $source[ $key ] );
	$owner        = basename( $file );
	foreach ( shipment_admin_js_lexical_owners( $source[ $key ], $owner ) as $name => $owners ) {
		foreach ( $owners as $occurrence_owner ) {
			$top_level_lexical_bindings[ $name ][] = $occurrence_owner;
		}
	}
	foreach ( $declarations['functions'] as $name => $kinds ) {
		$top_level_functions[ $name ][] = $owner . ' (function)';
	}
}

$duplicate_lexical = shipment_admin_js_duplicate_bindings( $top_level_lexical_bindings );
shipment_admin_js_structure_assert( array() === $duplicate_lexical, 'Admin JS bundle must not contain duplicate top-level const/let declarations. ' . shipment_admin_js_duplicate_binding_message( $duplicate_lexical ) );

$function_lexical_collisions = array();
foreach ( array_intersect( array_keys( $top_level_functions ), array_keys( $top_level_lexical_bindings ) ) as $name ) {
	$function_lexical_collisions[ $name ] = array_merge( $top_level_functions[ $name ], $top_level_lexical_bindings[ $name ] );
}
shipment_admin_js_structure_assert( array() === $function_lexical_collisions, 'Admin JS bundle must not contain function/lexical top-level name collisions. ' . shipment_admin_js_duplicate_binding_message( $function_lexical_collisions ) );

$metabox_source = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
foreach ( array( 'wdc-shipments-admin-core', 'wdc-shipments-admin-preview', 'wdc-shipments-admin-status', 'wdc-shipments-admin-polling', 'wdc-shipments-admin-picker', 'wdc-shipments-admin-yandex', 'wdc-shipments-admin-events' ) as $handle ) {
	shipment_admin_js_structure_assert( str_contains( $metabox_source, $handle ), 'Metabox enqueue must register script handle: ' . $handle );
}

echo "Shipment admin JS structure smoke passed.\n";
