<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use WallsShop\WDC\Locations\ValueObjects\Location;

use function defined;
use function is_array;
use function is_scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YandexDeliveryGeoRegionKeywordFilter {
	private const REGION_KEYWORDS = array(
		'Адыгея',
		'Алтай',
		'Алтайский',
		'Амурская',
		'Архангельская',
		'Астраханская',
		'Байконур',
		'Башкортостан',
		'Белгородская',
		'Брянская',
		'Бурятия',
		'Владимирская',
		'Волгоградская',
		'Вологодская',
		'Воронежская',
		'Дагестан',
		'Донецкая Народная',
		'Еврейская',
		'Забайкальский',
		'Запорожская',
		'Ивановская',
		'Ингушетия',
		'Иркутская',
		'Кабардино-Балкарская',
		'Калининградская',
		'Калмыкия',
		'Калужская',
		'Камчатский',
		'Карачаево-Черкесская',
		'Карелия',
		'Кемеровская',
		'Кировская',
		'Коми',
		'Костромская',
		'Краснодарский',
		'Красноярский',
		'Крым',
		'Курганская',
		'Курская',
		'Ленинградская',
		'Липецкая',
		'Луганская Народная',
		'Магаданская',
		'Марий Эл',
		'Мордовия',
		'Москва',
		'Московская',
		'Мурманская',
		'Ненецкий',
		'Нижегородская',
		'Новгородская',
		'Новосибирская',
		'Омская',
		'Оренбургская',
		'Орловская',
		'Пензенская',
		'Пермский',
		'Приморский',
		'Псковская',
		'Ростовская',
		'Рязанская',
		'Самарская',
		'Санкт-Петербург',
		'Саратовская',
		'Сахалинская',
		'Свердловская',
		'Севастополь',
		'Северная Осетия',
		'Смоленская',
		'Ставропольский',
		'Тамбовская',
		'Татарстан',
		'Тверская',
		'Томская',
		'Тульская',
		'Тыва',
		'Тюменская',
		'Удмуртская',
		'Ульяновская',
		'Хабаровский',
		'Хакасия',
		'Ханты-Мансийский',
		'Херсонская',
		'Челябинская',
		'Чеченская',
		'Чувашская',
		'Чукотский',
		'Якутия',
		'Ямало-Ненецкий',
		'Ярославская',
	);

	/** @return array<int,string> */
	public static function keywords(): array {
		return self::REGION_KEYWORDS;
	}

	public function keyword_for_location( Location $location ): string {
		$region_name = trim( $location->region_name );
		if ( '' === $region_name ) {
			return '';
		}

		$keywords = self::REGION_KEYWORDS;
		usort( $keywords, static fn( string $left, string $right ): int => self::length( $right ) <=> self::length( $left ) );
		foreach ( $keywords as $keyword ) {
			if ( $this->contains( $region_name, $keyword ) ) {
				return $keyword;
			}
		}

		return '';
	}

	/** @param array<int,array<string,mixed>> $rows @return array{rows:array<int,array<string,mixed>>,keyword:string,removed_candidates:int,filtered:bool} */
	public function filter( Location $location, array $rows ): array {
		$keyword = $this->keyword_for_location( $location );
		if ( '' === $keyword || array() === $rows ) {
			return array( 'rows' => array_values( $rows ), 'keyword' => $keyword, 'removed_candidates' => 0, 'filtered' => false );
		}

		$kept = array();
		foreach ( $rows as $row ) {
			if ( $this->candidate_contains_keyword( $row, $keyword ) ) {
				$kept[] = $row;
			}
		}

		return array(
			'rows' => $kept,
			'keyword' => $keyword,
			'removed_candidates' => max( 0, count( $rows ) - count( $kept ) ),
			'filtered' => count( $kept ) !== count( $rows ),
		);
	}

	/** @param array<string,mixed> $row */
	public function candidate_contains_keyword( array $row, string $keyword ): bool {
		$haystack = array(
			(string) ( $row['yandex_locality'] ?? '' ),
			(string) ( $row['yandex_region'] ?? '' ),
			(string) ( $row['raw_json'] ?? '' ),
		);
		$raw = json_decode( (string) ( $row['raw_json'] ?? '' ), true );
		if ( is_array( $raw ) && is_array( $raw['variant'] ?? null ) ) {
			$this->collect_variant_text( $raw['variant'], $haystack );
		}

		return $this->contains( implode( ' ', $haystack ), $keyword );
	}

	/** @param array<string,mixed> $variant @param array<int,string> $haystack */
	private function collect_variant_text( array $variant, array &$haystack ): void {
		foreach ( array( 'address', 'title', 'name', 'locality', 'city', 'region', 'region_name' ) as $key ) {
			$value = $variant[ $key ] ?? null;
			if ( is_scalar( $value ) ) {
				$haystack[] = (string) $value;
				continue;
			}
			if ( is_array( $value ) ) {
				foreach ( array( 'address', 'full_address', 'title', 'name', 'locality', 'city', 'region', 'region_name' ) as $nested_key ) {
					if ( is_scalar( $value[ $nested_key ] ?? null ) ) {
						$haystack[] = (string) $value[ $nested_key ];
					}
				}
			}
		}
	}

	private function contains( string $haystack, string $needle ): bool {
		$haystack = trim( $haystack );
		$needle = trim( $needle );
		if ( '' === $haystack || '' === $needle ) {
			return false;
		}
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle );
		}

		return false !== stripos( $haystack, $needle );
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}
}
