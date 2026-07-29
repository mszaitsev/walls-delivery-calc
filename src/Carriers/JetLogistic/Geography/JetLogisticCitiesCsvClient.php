<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCitiesCsvClient {
	public const DEFAULT_URL = 'https://jet7777.ru/cabinet/cities.csv';
	public const MAX_RESPONSE_BYTES = 20971520;

	public function fetch( string $url ): string {
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Не удалось скачать файл городов Jet Logistic.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			throw new \RuntimeException( 'Не удалось скачать cities.csv Jet Logistic: HTTP ' . $status . '.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			throw new \RuntimeException( 'Файл городов Jet Logistic пуст.' );
		}
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			throw new \RuntimeException( 'Размер файла городов Jet Logistic превышает допустимый лимит 20 МБ.' );
		}
		$trimmed = ltrim( $body );
		$prefix = strtolower( substr( $trimmed, 0, 32 ) );
		if ( str_starts_with( $prefix, '<!doctype html' ) || str_starts_with( $prefix, '<html' ) ) {
			throw new \RuntimeException( 'Сервер Jet Logistic вернул HTML-страницу вместо файла cities.csv.' );
		}

		return $body;
	}
}
