<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

defined( 'ABSPATH' ) || exit;

final class PekQuoteResult {
	/** @param array<int,array<string,mixed>> $services @param array<string,mixed> $safe_request @param array<string,mixed> $safe_response_meta @param array<int,array{field:string,messages:array<int,string>}> $field_errors */
	public function __construct(
		public readonly bool $success,
		public readonly string $mode,
		public readonly int $price_kopecks = 0,
		public readonly string $currency_code = '643',
		public readonly int $delivery_days = 0,
		public readonly string $sender_branch_id = '',
		public readonly string $sender_branch_title = '',
		public readonly string $receiver_branch_id = '',
		public readonly string $receiver_branch_title = '',
		public readonly int $product_type = 3,
		public readonly array $services = array(),
		public readonly array $safe_request = array(),
		public readonly array $safe_response_meta = array(),
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly string $failure_stage = '',
		public readonly string $endpoint = '',
		public readonly string $method = '',
		public readonly int|string $http_status = '',
		public readonly string $api_error_message = '',
		public readonly array $field_errors = array()
	) {
		if ( ! in_array( $mode, array( PekQuoteOptions::MODE_PICKUP, PekQuoteOptions::MODE_COURIER ), true ) ) {
			throw new \InvalidArgumentException( 'PEK quote result mode is invalid.' );
		}
		if ( $price_kopecks < 0 || $delivery_days < 0 || '643' !== $currency_code ) {
			throw new \InvalidArgumentException( 'PEK quote result values are invalid.' );
		}
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'mode' => $this->mode,
			'price_kopecks' => $this->price_kopecks,
			'currency_code' => $this->currency_code,
			'delivery_days' => $this->delivery_days,
			'sender_branch_id' => $this->sender_branch_id,
			'sender_branch_title' => $this->sender_branch_title,
			'receiver_branch_id' => $this->receiver_branch_id,
			'receiver_branch_title' => $this->receiver_branch_title,
			'product_type' => $this->product_type,
			'services' => $this->services,
			'safe_request' => $this->safe_request,
			'safe_response_meta' => $this->safe_response_meta,
			'error_code' => $this->error_code,
			'error_message' => $this->error_message,
			'failure_stage' => $this->failure_stage,
			'endpoint' => $this->endpoint,
			'method' => $this->method,
			'http_status' => $this->http_status,
			'api_error_message' => $this->api_error_message,
			'field_errors' => $this->field_errors,
		);
	}
}
