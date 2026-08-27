<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusMapper {
	public function __construct( private JetLogisticStatusMappingRepository $repository ) {
	}

	public function map( string $message ): string {
		return $this->repository->map( $message );
	}

	/** @return array<string,mixed> */
	public function match_mapping( string $message ): array {
		return $this->repository->match_mapping( $message );
	}
}
