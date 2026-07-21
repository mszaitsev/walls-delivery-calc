<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsFilter;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsResult;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsRow;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsService;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsAdminSection {
	public function __construct(
		private ShipmentCostAnalyticsService $analytics,
		private ShipmentCostThresholdPolicy $threshold
	) {
	}

	public function render(): void {
		$carrier_options = $this->analytics->carrier_options();
		$filter = ShipmentCostAnalyticsFilter::from_request( $this->request(), $carrier_options );
		$result = $this->analytics->result( $filter );
		foreach ( $filter->notices as $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
		}
		echo '<hr style="margin: 28px 0;">';
		echo '<section class="wdc-shipment-cost-analytics">';
		echo '<h2>' . esc_html__( 'Аналитика стоимости отправлений', 'walls-delivery-calc' ) . '</h2>';
		echo '<p>' . esc_html__( 'Сравнение плановой базовой стоимости API с фактической стоимостью созданных отправлений.', 'walls-delivery-calc' ) . '</p>';
		$this->render_filters( $filter, $carrier_options );
		echo '<p><strong>' . esc_html__( 'Период:', 'walls-delivery-calc' ) . '</strong> ' . esc_html( $this->format_date_range( $filter ) ) . '</p>';
		echo '<p class="description">' . esc_html( sprintf( 'Зелёный — фактическая цена не превышает плановую более чем на %d%%. Красный — превышение более %d%%.', ShipmentCostThresholdPolicy::ALLOWED_OVERAGE_PERCENT, ShipmentCostThresholdPolicy::ALLOWED_OVERAGE_PERCENT ) ) . '</p>';
		$this->render_table( $filter, $result );
		$this->render_summary( $result );
		echo '</section>';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function request(): array {
		$request = array();
		foreach ( $_GET as $key => $value ) {
			$request[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? wp_unslash( $value ) : '';
		}

		return $request;
	}

	/**
	 * @param array<string,string> $carrier_options
	 */
	private function render_filters( ShipmentCostAnalyticsFilter $filter, array $carrier_options ): void {
		echo '<form method="get" style="margin: 16px 0; padding: 12px; border: 1px solid #ccd0d4; background: #fff;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( AdminMenu::MENU_SLUG ) . '">';
		echo '<input type="hidden" name="paged" value="1">';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Период', 'walls-delivery-calc' ) . ' ';
		echo '<select name="analytics_period">';
		foreach ( array( 'week' => 'Последняя неделя', 'month' => 'Последний месяц', 'quarter' => 'Последний квартал', 'year' => 'Последний год', 'custom' => 'Произвольный период' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . $this->selected_attr( $filter->period, $value ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Дата от', 'walls-delivery-calc' ) . ' <input type="date" name="date_from" value="' . esc_attr( $filter->date_from ) . '"></label>';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Дата до', 'walls-delivery-calc' ) . ' <input type="date" name="date_to" value="' . esc_attr( $filter->date_to ) . '"></label>';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Перевозчик', 'walls-delivery-calc' ) . ' <select name="carrier"><option value="">' . esc_html__( 'Все службы доставки', 'walls-delivery-calc' ) . '</option>';
		foreach ( $carrier_options as $key => $title ) {
			echo '<option value="' . esc_attr( $key ) . '"' . $this->selected_attr( $filter->carrier_key, $key ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label>';
		echo '<label style="margin-right: 12px;"><input type="checkbox" name="actual_cost_mode" value="all"' . $this->checked_attr( $filter->include_missing_actual ) . '> ' . esc_html__( 'Показать без фактической стоимости', 'walls-delivery-calc' ) . '</label>';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Поиск по номеру заказа', 'walls-delivery-calc' ) . ' <input type="search" name="order_search" value="' . esc_attr( $filter->order_search ) . '"></label>';
		echo '<label style="margin-right: 12px;">' . esc_html__( 'Строк', 'walls-delivery-calc' ) . ' <select name="per_page">';
		foreach ( array( 25, 50, 100 ) as $per_page ) {
			echo '<option value="' . esc_attr( (string) $per_page ) . '"' . $this->selected_attr( $filter->per_page, $per_page ) . '>' . esc_html( (string) $per_page ) . '</option>';
		}
		echo '</select></label>';
		echo '<input type="hidden" name="orderby" value="' . esc_attr( $filter->orderby ) . '">';
		echo '<input type="hidden" name="order" value="' . esc_attr( $filter->order ) . '">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Применить', 'walls-delivery-calc' ) . '</button> ';
		echo '<a class="button button-secondary" href="' . $this->esc_url_value( admin_url( 'admin.php?page=' . AdminMenu::MENU_SLUG ) ) . '">' . esc_html__( 'Сбросить', 'walls-delivery-calc' ) . '</a>';
		echo '</form>';
	}

	private function render_table( ShipmentCostAnalyticsFilter $filter, ShipmentCostAnalyticsResult $result ): void {
		if ( 0 === $result->total_rows ) {
			$message = '' !== $filter->order_search
				? sprintf( 'Заказ с номером %s не найден в выбранном периоде.', $filter->order_search )
				: ( $filter->include_missing_actual ? 'За выбранный период созданных отправлений не найдено.' : 'За выбранный период нет отправлений с сохранённой фактической стоимостью. Отключите фильтр «Только с фактической стоимостью», чтобы увидеть все отправления.' );
			echo '<p><em>' . esc_html( $message ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'order_number' => 'Номер заказа', 'date' => 'Дата заказа', 'carrier' => 'Служба доставки', 'base' => 'Плановая цена', 'actual' => 'Фактическая цена', 'difference' => 'Отклонение, ₽', 'difference_percent' => 'Отклонение, %' ) as $key => $label ) {
			echo '<th scope="col">' . $this->sort_link( $filter, $key, $label ) . '</th>';
		}
		echo '<th scope="col">' . esc_html__( 'Источник фактической цены', 'walls-delivery-calc' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $result->rows as $row ) {
			$this->render_row( $row );
		}
		echo '</tbody></table>';
		$this->render_pagination( $filter, $result );
	}

	private function render_row( ShipmentCostAnalyticsRow $row ): void {
		$class = match ( $row->threshold_status ) {
			ShipmentCostThresholdPolicy::STATUS_WITHIN_THRESHOLD => 'notice-success',
			ShipmentCostThresholdPolicy::STATUS_OVER_THRESHOLD => 'notice-error',
			default => '',
		};
		$title = $this->status_label( $row->threshold_status );
		echo '<tr>';
		echo '<td><a href="' . $this->esc_url_value( $row->order_edit_url ) . '">#' . esc_html( $row->order_number ) . '</a></td>';
		echo '<td>' . esc_html( $this->format_datetime( $row->order_created_at ) ) . '</td>';
		echo '<td><strong>' . esc_html( $row->carrier_title ) . '</strong>' . ( '' !== $row->service_title ? '<br><span class="description">' . esc_html( $row->service_title ) . '</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( $this->format_money( $row->base_api_cost_kopecks ) ) . '</td>';
		echo '<td><span class="' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $this->format_money( $row->actual_cost_kopecks ) ) . '</span></td>';
		echo '<td><span class="' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $this->format_money( $row->difference_kopecks ) ) . '</span></td>';
		echo '<td><span class="' . esc_attr( $class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $this->format_percent( $row->difference_percent_basis_points ) ) . '</span></td>';
		echo '<td>' . esc_html( $this->source_label( $row->actual_cost_source ) ) . ( '' !== $row->actual_cost_source_detail ? '<br><span class="description" title="' . esc_attr( $row->actual_cost_source_detail ) . '">' . esc_html( $this->detail_label( $row->actual_cost_source_detail ) ) . '</span>' : '' ) . '</td>';
		echo '</tr>';
	}

	private function render_summary( ShipmentCostAnalyticsResult $result ): void {
		$summary = $result->summary;
		$label = $summary->difference_total_kopecks < 0 ? 'Экономия' : ( $summary->difference_total_kopecks > 0 ? 'Перерасход' : 'Без отклонения' );
		echo '<h3>' . esc_html__( 'Итоги', 'walls-delivery-calc' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width: 760px;"><tbody>';
		foreach ( array(
			'Количество отправлений' => (string) $summary->shipment_count,
			'Количество с фактической стоимостью' => (string) $summary->with_actual_count,
			'Количество без фактической стоимости' => (string) $summary->without_actual_count,
			'Плановая сумма' => $this->format_money( $summary->planned_total_kopecks ),
			'Фактическая сумма' => $this->format_money( $summary->actual_total_kopecks ),
			$label => $this->format_money( $summary->difference_total_kopecks ),
			'Среднее процентное отклонение' => $this->format_percent( $summary->average_difference_percent_basis_points ),
			'Количество превышений более 3%' => (string) $summary->over_threshold_count,
			'Доля превышений более 3%' => $this->format_percent( $summary->over_threshold_share_basis_points() ),
		) as $name => $value ) {
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_pagination( ShipmentCostAnalyticsFilter $filter, ShipmentCostAnalyticsResult $result ): void {
		if ( $result->total_pages <= 1 ) {
			return;
		}
		echo '<p>';
		for ( $page = 1; $page <= $result->total_pages; ++$page ) {
			$url = $this->url( $filter, array( 'paged' => $page ) );
			echo $page === $filter->page ? ' <strong>' . esc_html( (string) $page ) . '</strong> ' : ' <a href="' . $this->esc_url_value( $url ) . '">' . esc_html( (string) $page ) . '</a> ';
		}
		echo '</p>';
	}

	private function sort_link( ShipmentCostAnalyticsFilter $filter, string $orderby, string $label ): string {
		$next = $filter->orderby === $orderby && 'asc' === $filter->order ? 'desc' : 'asc';

		return '<a href="' . $this->esc_url_value( $this->url( $filter, array( 'orderby' => $orderby, 'order' => $next, 'paged' => 1 ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function url( ShipmentCostAnalyticsFilter $filter, array $overrides = array() ): string {
		$args = array_merge(
			array(
				'page' => AdminMenu::MENU_SLUG,
				'analytics_period' => $filter->period,
				'date_from' => $filter->date_from,
				'date_to' => $filter->date_to,
				'carrier' => $filter->carrier_key ?? '',
				'actual_cost_mode' => $filter->include_missing_actual ? 'all' : 'with_actual',
				'order_search' => $filter->order_search,
				'orderby' => $filter->orderby,
				'order' => $filter->order,
				'paged' => $filter->page,
				'per_page' => $filter->per_page,
			),
			$overrides
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function format_money( ?int $kopecks ): string {
		if ( null === $kopecks ) {
			return '—';
		}
		$value = number_format( $kopecks / 100, 2, '.', '' );
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $value ) );
		}

		return $value . ' руб.';
	}

	private function format_percent( ?int $basis_points ): string {
		if ( null === $basis_points ) {
			return '—';
		}

		return number_format_i18n( $basis_points / 100, 1 ) . '%';
	}

	private function format_datetime( string $date ): string {
		if ( '' === $date ) {
			return '—';
		}
		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}
		$format = function_exists( 'wc_date_format' ) && function_exists( 'wc_time_format' ) ? wc_date_format() . ' ' . wc_time_format() : 'd.m.Y H:i';

		return function_exists( 'wp_date' ) ? wp_date( $format, $timestamp ) : date( 'd.m.Y H:i', $timestamp );
	}

	private function format_date_range( ShipmentCostAnalyticsFilter $filter ): string {
		return $filter->date_from . '–' . $filter->date_to;
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			ShipmentCostThresholdPolicy::STATUS_WITHIN_THRESHOLD => 'В пределах плана',
			ShipmentCostThresholdPolicy::STATUS_OVER_THRESHOLD => 'Превышение более 3%',
			default => 'Нет данных для сравнения',
		};
	}

	private function source_label( string $source ): string {
		return match ( $source ) {
			'manual' => 'Вручную',
			'carrier_api', 'carrier_status' => 'API перевозчика',
			'carrier_reconciliation' => 'Сверка перевозчика',
			default => '' !== $source ? $source : '—',
		};
	}

	private function detail_label( string $detail ): string {
		return trim( str_replace( '_', ' ', $detail ) );
	}

	private function selected_attr( mixed $actual, mixed $expected ): string {
		return (string) $actual === (string) $expected ? ' selected="selected"' : '';
	}

	private function checked_attr( bool $checked ): string {
		return $checked ? ' checked="checked"' : '';
	}

	private function esc_url_value( string $url ): string {
		return function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}
