<?php
declare(strict_types=1);

$defaults = array(
	'from'    => '630005',
	'to'      => '101000',
	'weight'  => '1000',
	'sumoc'   => '500000',
	'objects' => '27030,27020,4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020',
	'date'    => date( 'Ymd' ),
);

$declared_value_objects = array( '27020', '4020', '47020', '54020', '23020', '24020' );
$options                = rpd_parse_cli_options( $argv, $defaults );
$objects                = rpd_parse_objects( $options['objects'] );

if ( array() === $objects ) {
	fwrite( STDERR, "No object codes provided.\n" );
	exit( 1 );
}

$summaries = array();
foreach ( $objects as $object ) {
	$uses_sumoc = in_array( $object, $declared_value_objects, true );
	$params     = rpd_request_params( $options, $object, $uses_sumoc, false );
	$url        = rpd_build_url( $params );

	if ( $options['dry_run'] ) {
		$summaries[] = array(
			'object'     => $object,
			'dry_run'    => true,
			'uses_sumoc' => $uses_sumoc,
			'url'        => $url,
		);
		continue;
	}

	$summary = rpd_probe_object( $object, $url );
	if ( ! $summary['success'] && ! rpd_has_group_param( $url ) ) {
		$group_url     = rpd_build_url( rpd_request_params( $options, $object, $uses_sumoc, true ) );
		$group_summary = rpd_probe_object( $object, $group_url );

		$summary['fallback_group_0'] = array(
			'attempted' => true,
			'success'   => $group_summary['success'],
			'url'       => $group_url,
			'summary'   => $group_summary,
		);

		if ( $group_summary['success'] ) {
			$summary = array_merge(
				$group_summary,
				array(
					'initial_without_group' => $summary,
					'used_group_0'          => true,
				)
			);
		}
	}

	$summaries[] = $summary;
}

echo rpd_json( array( 'generated_at' => gmdate( DATE_ATOM ), 'results' => $summaries ) ) . "\n";

/**
 * @param array<int,string> $argv
 * @param array<string,string> $defaults
 * @return array<string,mixed>
 */
function rpd_parse_cli_options( array $argv, array $defaults ): array {
	$options            = $defaults;
	$options['dry_run'] = false;

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( '--dry-run' === $arg ) {
			$options['dry_run'] = true;
			continue;
		}

		if ( ! str_starts_with( $arg, '--' ) || ! str_contains( $arg, '=' ) ) {
			fwrite( STDERR, "Ignoring unsupported argument: {$arg}\n" );
			continue;
		}

		list( $key, $value ) = explode( '=', substr( $arg, 2 ), 2 );
		if ( array_key_exists( $key, $defaults ) ) {
			$options[ $key ] = trim( $value );
		}
	}

	foreach ( array( 'from', 'to', 'weight', 'sumoc' ) as $numeric_key ) {
		$options[ $numeric_key ] = preg_replace( '/\D+/', '', (string) $options[ $numeric_key ] ) ?: $defaults[ $numeric_key ];
	}

	$options['date'] = preg_match( '/^\d{8}$/', (string) $options['date'] ) ? (string) $options['date'] : $defaults['date'];

	return $options;
}

/**
 * @return array<int,string>
 */
function rpd_parse_objects( string $objects ): array {
	return array_values(
		array_unique(
			array_filter(
				array_map(
					static fn ( string $object ): string => preg_replace( '/\D+/', '', trim( $object ) ) ?: '',
					explode( ',', $objects )
				),
				static fn ( string $object ): bool => '' !== $object
			)
		)
	);
}

/**
 * @param array<string,mixed> $options
 * @return array<string,string>
 */
function rpd_request_params( array $options, string $object, bool $uses_sumoc, bool $with_group ): array {
	$params = array(
		'json'      => '',
		'errorcode' => '0',
		'object'    => $object,
		'from'      => (string) $options['from'],
		'to'        => (string) $options['to'],
		'weight'    => (string) $options['weight'],
		'date'      => (string) $options['date'],
	);

	if ( $uses_sumoc ) {
		$params['sumoc'] = (string) $options['sumoc'];
	}

	if ( $with_group ) {
		$params['group'] = '0';
	}

	return $params;
}

/**
 * @param array<string,string> $params
 */
function rpd_build_url( array $params ): string {
	$query = array();
	foreach ( $params as $key => $value ) {
		$query[] = '' === $value ? rawurlencode( $key ) : rawurlencode( $key ) . '=' . rawurlencode( $value );
	}

	return 'https://tariff.pochta.ru/v2/calculate/tariff/delivery?' . implode( '&', $query );
}

function rpd_probe_object( string $object, string $url ): array {
	$response = rpd_http_get_json( $url );
	$data     = is_array( $response['json'] ) ? $response['json'] : array();
	$errors   = rpd_extract_errors( $data, $response );

	return array(
		'object'           => $object,
		'success'          => 200 <= $response['http_code'] && $response['http_code'] < 300 && array() === $errors,
		'http_code'        => $response['http_code'],
		'url'              => $url,
		'errors'           => $errors,
		'pay'              => rpd_first_value( $data, array( 'pay', 'paymoney' ) ),
		'nds'              => rpd_first_value( $data, array( 'nds', 'ndsrate' ) ),
		'paynds'           => rpd_first_value( $data, array( 'paynds' ) ),
		'delivery'         => array(
			'min' => rpd_nested_value( $data, array( 'delivery', 'min' ) ),
			'max' => rpd_nested_value( $data, array( 'delivery', 'max' ) ),
		),
		'transtype'        => rpd_first_value( $data, array( 'transtype' ) ),
		'delivery-to'      => rpd_first_value( $data, array( 'delivery-to', 'delivery_to' ) ),
		'postoffice_count' => count( rpd_list_value( $data['postoffice'] ?? array() ) ),
		'postoffice'       => rpd_postoffice_summary( $data['postoffice'] ?? array() ),
		'items'            => rpd_items_summary( $data['items'] ?? array() ),
	);
}

/**
 * @return array{http_code:int,json:mixed,error:string}
 */
function rpd_http_get_json( string $url ): array {
	$body      = false;
	$http_code = 0;
	$error     = '';

	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_USERAGENT      => 'walls-delivery-calc-russian-post-domestic-probe/0.21.18',
			)
		);
		$body = curl_exec( $ch );
		if ( false === $body ) {
			$error = curl_error( $ch );
		}
		$http_code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
	} else {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => 30,
					'ignore_errors' => true,
					'header'        => "User-Agent: walls-delivery-calc-russian-post-domestic-probe/0.21.18\r\n",
				),
			)
		);
		$body    = @file_get_contents( $url, false, $context );
		$headers = $http_response_header ?? array();
		foreach ( $headers as $header ) {
			if ( preg_match( '/^HTTP\/\S+\s+(\d+)/', $header, $matches ) ) {
				$http_code = (int) $matches[1];
			}
		}
		if ( false === $body ) {
			$error = 'HTTP request failed.';
		}
	}

	$json = false !== $body ? json_decode( (string) $body, true ) : null;
	if ( false !== $body && JSON_ERROR_NONE !== json_last_error() ) {
		$error = 'JSON decode failed: ' . json_last_error_msg();
	}

	return array(
		'http_code' => $http_code,
		'json'      => $json,
		'error'     => $error,
	);
}

/**
 * @param array<string,mixed> $data
 * @param array{http_code:int,json:mixed,error:string} $response
 * @return array<int,array<string,mixed>>
 */
function rpd_extract_errors( array $data, array $response ): array {
	$errors = array();
	if ( '' !== $response['error'] ) {
		$errors[] = array( 'code' => $response['http_code'], 'msg' => $response['error'], 'type' => 'transport' );
	}

	foreach ( array( 'errors', 'error' ) as $key ) {
		if ( empty( $data[ $key ] ) ) {
			continue;
		}

		if ( ! is_array( $data[ $key ] ) ) {
			$errors[] = array( 'code' => $data['errorcode'] ?? null, 'msg' => (string) $data[ $key ], 'type' => $key );
			continue;
		}

		foreach ( rpd_list_value( $data[ $key ] ) as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = array(
					'code' => $error['code'] ?? $error['errorcode'] ?? null,
					'msg'  => $error['msg'] ?? $error['message'] ?? $error['errormsg'] ?? null,
					'type' => $error['type'] ?? $key,
				);
			} else {
				$errors[] = array( 'code' => null, 'msg' => (string) $error, 'type' => $key );
			}
		}
	}

	foreach ( array( 'errorcode', 'errormsg' ) as $key ) {
		if ( ! empty( $data[ $key ] ) ) {
			$errors[] = array(
				'code' => $data['errorcode'] ?? null,
				'msg'  => $data['errormsg'] ?? $data['error'] ?? null,
				'type' => 'api',
			);
			break;
		}
	}

	return $errors;
}

/**
 * @param array<string,mixed> $data
 * @param array<int,string> $keys
 */
function rpd_first_value( array $data, array $keys ): mixed {
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}
	}

	return null;
}

/**
 * @param array<string,mixed> $data
 * @param array<int,string> $path
 */
function rpd_nested_value( array $data, array $path ): mixed {
	$value = $data;
	foreach ( $path as $key ) {
		if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
			return null;
		}
		$value = $value[ $key ];
	}

	return $value;
}

/**
 * @return array<int,mixed>
 */
function rpd_list_value( mixed $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	if ( array_is_list( $value ) ) {
		return $value;
	}

	return array( $value );
}

/**
 * @return array<int,array<string,mixed>>
 */
function rpd_postoffice_summary( mixed $postoffice ): array {
	return array_map(
		static fn ( mixed $row ): array => is_array( $row )
			? array(
				'index'      => $row['index'] ?? null,
				'name'       => $row['name'] ?? null,
				'tp'         => $row['tp'] ?? null,
				'pvz'        => $row['pvz'] ?? null,
				'move'       => $row['move'] ?? null,
				'weight-max' => $row['weight-max'] ?? $row['weight_max'] ?? null,
				'closed'     => $row['closed'] ?? null,
			)
			: array(),
		rpd_list_value( $postoffice )
	);
}

/**
 * @return array<int,array<string,mixed>>
 */
function rpd_items_summary( mixed $items ): array {
	return array_map(
		static fn ( mixed $row ): array => is_array( $row )
			? array(
				'name'      => $row['name'] ?? null,
				'serviceon' => $row['serviceon'] ?? null,
				'tariff'    => array(
					'valnds' => is_array( $row['tariff'] ?? null ) ? ( $row['tariff']['valnds'] ?? null ) : null,
				),
				'delivery'  => array(
					'min' => is_array( $row['delivery'] ?? null ) ? ( $row['delivery']['min'] ?? null ) : null,
					'max' => is_array( $row['delivery'] ?? null ) ? ( $row['delivery']['max'] ?? null ) : null,
				),
			)
			: array(),
		rpd_list_value( $items )
	);
}

function rpd_has_group_param( string $url ): bool {
	return (bool) preg_match( '/[?&]group=/', $url );
}

function rpd_json( mixed $value ): string {
	$json = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	return false !== $json ? $json : '{}';
}
