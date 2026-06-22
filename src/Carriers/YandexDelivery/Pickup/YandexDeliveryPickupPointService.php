<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointService {
	public function __construct(
		private YandexDeliveryPickupPointRepository $repository,
		private YandexDeliverySettings $settings
	) {
	}

	/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	public function search( array $filters = array() ): array {
		return $this->repository->search( $filters );
	}

	public function get_by_platform_station_id( string $platform_station_id ): ?array {
		return $this->repository->find_by_platform_station_id( $platform_station_id );
	}

	/** @return array{active:int,by_type:array<string,int>,dropoff_available:int,last_import:string} */
	public function statistics(): array {
		$last = $this->settings->last_pickup_import_report();

		return array(
			'active' => $this->repository->count_active(),
			'by_type' => $this->repository->count_by_type(),
			'dropoff_available' => $this->repository->count_dropoff_available(),
			'last_import' => (string) ( $last['finished_at'] ?? '' ),
		);
	}

	/** @return array{platform_station_id:string,found:bool,valid:bool,point:?array<string,mixed>,message:string} */
	public function validate_sender_point(): array {
		$station_id = $this->settings->credentials()->platform_station_id;
		if ( '' === trim( $station_id ) ) {
			return array(
				'platform_station_id' => '',
				'found' => false,
				'valid' => false,
				'point' => null,
				'message' => 'platform_station_id активной среды не задан.',
			);
		}
		$point = $this->repository->find_by_platform_station_id( $station_id );
		if ( null === $point ) {
			return array(
				'platform_station_id' => $station_id,
				'found' => false,
				'valid' => false,
				'point' => null,
				'message' => 'Точка отправителя не найдена среди активных точек.',
			);
		}
		$valid = ! empty( $point['available_for_dropoff'] );

		return array(
			'platform_station_id' => $station_id,
			'found' => true,
			'valid' => $valid,
			'point' => $point,
			'message' => $valid ? 'Точка отправителя найдена и доступна для dropoff.' : 'Точка отправителя найдена, но available_for_dropoff=false.',
		);
	}
}
