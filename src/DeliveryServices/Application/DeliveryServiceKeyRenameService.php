<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices\Application;

use InvalidArgumentException;
use RuntimeException;
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Rules\Storage\RuleRepository;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceKeyRenameService {
	public function __construct(
		private DeliveryServiceRepository $services,
		private RuleRepository $rules
	) {
	}

	public function rename_manual_service( int $service_id, string $new_service_key ): DeliveryService {
		$service = $this->services->find_by_id( $service_id );
		if ( ! $service instanceof DeliveryService || null === $service->id ) {
			throw new InvalidArgumentException( 'delivery_service_not_found' );
		}
		if ( ! $this->is_manual_service( $service ) ) {
			throw new InvalidArgumentException( 'delivery_service_key_locked' );
		}

		$new_service_key = sanitize_key( $new_service_key );
		if ( '' === $new_service_key ) {
			throw new InvalidArgumentException( 'delivery_service_key_empty' );
		}
		if ( $this->services->is_predefined_service_key( $new_service_key ) ) {
			throw new InvalidArgumentException( 'delivery_service_key_predefined' );
		}
		if ( $service->service_key === $new_service_key ) {
			return $service;
		}
		if ( $this->services->service_key_exists_for_other_service( $new_service_key, $service_id ) ) {
			throw new InvalidArgumentException( 'delivery_service_key_duplicate' );
		}

		$old_service_key = $service->service_key;
		$this->services->update_service( $service_id, array( 'service_key' => $new_service_key ) );
		$updated = $this->services->find_by_id( $service_id );
		if ( ! $updated instanceof DeliveryService || $updated->service_key !== $new_service_key ) {
			throw new RuntimeException( 'delivery_service_key_rename_failed' );
		}

		$this->rules->rename_service_target( $old_service_key, $new_service_key );

		return $updated;
	}

	private function is_manual_service( DeliveryService $service ): bool {
		return ManualDeliverySettings::CARRIER_KEY === $service->carrier_key
			&& DeliveryService::TYPE_MANUAL === $service->service_type;
	}
}
