<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostCountryMapping {
	public const MODE_AUTO     = 'auto';
	public const MODE_ENABLED  = 'enabled';
	public const MODE_DISABLED = 'disabled';

	/**
	 * @param array<string,mixed> $raw
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $wc_country_code,
		public readonly string $wc_country_name,
		public readonly string $rp_country_id,
		public readonly string $rp_country_name,
		public readonly string $rp_iso2,
		public readonly bool $has_parcel,
		public readonly bool $parcel_block,
		public readonly bool $api_available,
		public readonly bool $matched,
		public readonly string $match_source,
		public readonly string $manual_mode,
		public readonly bool $effective_enabled,
		public readonly ?string $last_checked_at,
		public readonly string $manual_comment,
		public readonly array $raw,
		public readonly string $created_at,
		public readonly string $updated_at
	) {
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function from_row( array $row ): self {
		$raw_json = (string) ( $row['raw_json'] ?? '' );
		$decoded  = '' !== $raw_json ? json_decode( $raw_json, true ) : array();

		return new self(
			(int) ( $row['id'] ?? 0 ),
			strtoupper( (string) ( $row['wc_country_code'] ?? '' ) ),
			(string) ( $row['wc_country_name'] ?? '' ),
			(string) ( $row['rp_country_id'] ?? '' ),
			(string) ( $row['rp_country_name'] ?? '' ),
			strtoupper( (string) ( $row['rp_iso2'] ?? '' ) ),
			! empty( $row['has_parcel'] ),
			! empty( $row['parcel_block'] ),
			! empty( $row['api_available'] ),
			! empty( $row['matched'] ),
			self::normalize_match_source( (string) ( $row['match_source'] ?? 'none' ) ),
			self::normalize_mode( (string) ( $row['manual_mode'] ?? self::MODE_AUTO ) ),
			! empty( $row['effective_enabled'] ),
			isset( $row['last_checked_at'] ) && null !== $row['last_checked_at'] ? (string) $row['last_checked_at'] : null,
			(string) ( $row['manual_comment'] ?? '' ),
			is_array( $decoded ) ? $decoded : array(),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                => $this->id,
			'wc_country_code'   => $this->wc_country_code,
			'wc_country_name'   => $this->wc_country_name,
			'rp_country_id'     => $this->rp_country_id,
			'rp_country_name'   => $this->rp_country_name,
			'rp_iso2'           => $this->rp_iso2,
			'has_parcel'        => $this->has_parcel,
			'parcel_block'      => $this->parcel_block,
			'api_available'     => $this->api_available,
			'matched'           => $this->matched,
			'match_source'      => $this->match_source,
			'manual_mode'       => $this->manual_mode,
			'effective_enabled' => $this->effective_enabled,
			'last_checked_at'   => $this->last_checked_at,
			'manual_comment'    => $this->manual_comment,
			'raw'               => $this->raw,
			'created_at'        => $this->created_at,
			'updated_at'        => $this->updated_at,
		);
	}

	public static function normalize_mode( string $mode ): string {
		return in_array( $mode, array( self::MODE_AUTO, self::MODE_ENABLED, self::MODE_DISABLED ), true ) ? $mode : self::MODE_AUTO;
	}

	public static function normalize_match_source( string $source ): string {
		return in_array( $source, array( 'name', 'alias', 'manual', 'none' ), true ) ? $source : 'none';
	}
}
