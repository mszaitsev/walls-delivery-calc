<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Gar;

use WallsShop\WDC\Locations\Fias\FiasHttpClient;

defined( 'ABSPATH' ) || exit;

final class GarChangesClient {
	private const BASE_URL = 'https://fias-public-service.nalog.ru/api/spas/v2.0';

	public function __construct( private FiasHttpClient $http_client ) {
	}

	public function request_changes(): array {
		return $this->safe_get( self::BASE_URL . '/GetAllDownloadFileInfo' );
	}

	public function get_task_status( string $task_id ): array {
		return $this->safe_get( self::BASE_URL . '/GetTaskStatus?' . http_build_query( array( 'taskId' => $task_id ) ) );
	}

	public function get_result_block( string $task_id, int $block = 0 ): array {
		return $this->safe_get( self::BASE_URL . '/GetResultBlock?' . http_build_query( array( 'taskId' => $task_id, 'block' => max( 0, $block ) ) ) );
	}

	private function safe_get( string $url ): array {
		try {
			return $this->http_client->get( $url );
		} catch ( \Throwable $exception ) {
			return array(
				'success'       => false,
				'status_code'   => 0,
				'body'          => null,
				'error_message' => $exception->getMessage(),
				'timeout'       => false,
			);
		}
	}
}
