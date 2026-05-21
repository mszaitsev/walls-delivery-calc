<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Fias;

defined( 'ABSPATH' ) || exit;

final class FiasEndpoints {
	private const BASE_URL = 'https://fias-public-service.nalog.ru/api/spas/v2.0';

	public function search( string $query = '' ): string {
		return $this->with_query( self::BASE_URL . '/GetAddressItems', array( 'searchText' => $query ) );
	}

	public function normalize(): string {
		return self::BASE_URL . '/SearchAddressItems';
	}

	public function changes(): string {
		return self::BASE_URL . '/GetAllDownloadFileInfo';
	}

	private function with_query( string $url, array $query ): string {
		$query = array_filter( $query, static fn( mixed $value ): bool => '' !== trim( (string) $value ) );
		if ( array() === $query ) {
			return $url;
		}

		return $url . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
}
