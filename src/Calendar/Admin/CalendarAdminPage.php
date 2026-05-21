<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Admin;

use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Calendar\CalendarDay;

defined( 'ABSPATH' ) || exit;

final class CalendarAdminPage {
	private const PAGE_SLUG = 'wdc-platform-calendars';
	private const NONCE_ACTION = 'wdc_save_calendar_year';
	private const NONCE_NAME = 'wdc_calendar_nonce';

	public function __construct(
		private PluginEnvironment $environment,
		private CalendarService $calendar_service,
		private CalendarRepository $repository,
		private YearGenerator $year_generator
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Календари', 'walls-delivery-calc' ), esc_html__( 'Календари', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-calendar-admin', $this->environment->plugin_url() . 'assets/admin/calendar-admin.css', array(), $this->environment->version() );
		wp_enqueue_script( 'wdc-calendar-admin', $this->environment->plugin_url() . 'assets/admin/calendar-admin.js', array(), $this->environment->version(), true );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$calendar_type = $this->requested_calendar_type();
		$year          = $this->requested_year();
		$message       = $this->handle_post( $calendar_type, $year );

		$this->calendar_service->ensure_year_exists( $calendar_type, $year );
		$days = $this->repository->get_year( $calendar_type, $year );
		?>
		<div class="wrap wdc-calendar-admin">
			<h1><?php echo esc_html__( 'Календари', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<form class="wdc-calendar-filters" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label><span><?php echo esc_html__( 'Календарь', 'walls-delivery-calc' ); ?></span>
					<select name="calendar_type">
						<?php foreach ( $this->calendar_labels() as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $calendar_type, $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span><?php echo esc_html__( 'Год', 'walls-delivery-calc' ); ?></span>
					<input type="number" name="year" min="2026" max="2100" value="<?php echo esc_attr( (string) $year ); ?>">
				</label>
				<button class="button" type="submit"><?php echo esc_html__( 'Открыть', 'walls-delivery-calc' ); ?></button>
			</form>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="calendar_type" value="<?php echo esc_attr( $calendar_type ); ?>">
				<input type="hidden" name="year" value="<?php echo esc_attr( (string) $year ); ?>">
				<div class="wdc-calendar-toolbar">
					<button class="button" type="submit" name="wdc_calendar_action" value="generate"><?php echo esc_html__( 'Сгенерировать год', 'walls-delivery-calc' ); ?></button>
					<button class="button button-primary" type="submit" name="wdc_calendar_action" value="save"><?php echo esc_html__( 'Сохранить календарь', 'walls-delivery-calc' ); ?></button>
				</div>
				<div class="wdc-calendar-grid">
					<?php foreach ( range( 1, 12 ) as $month ) : ?>
						<?php $this->render_month( $calendar_type, $year, $month, $days ); ?>
					<?php endforeach; ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string, CalendarDay> $days
	 */
	private function render_month( string $calendar_type, int $year, int $month, array $days ): void {
		$timezone = new DateTimeZone( TimezoneService::TIMEZONE );
		$start    = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $timezone );
		$period   = new DatePeriod( $start, new \DateInterval( 'P1D' ), $start->modify( '+1 month' ) );
		?>
		<section class="wdc-calendar-month">
			<h2><?php echo esc_html( $start->format( 'F Y' ) ); ?></h2>
			<div class="wdc-calendar-weekdays">
				<?php foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $weekday ) : ?>
					<span><?php echo esc_html( $weekday ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="wdc-calendar-days" style="--wdc-month-offset: <?php echo esc_attr( (string) ( (int) $start->format( 'N' ) - 1 ) ); ?>">
				<?php foreach ( $period as $date ) : ?>
					<?php
					$date_value = $date->format( 'Y-m-d' );
					$day        = $days[ $date_value ] ?? new CalendarDay( $date_value, false, 'generated', $calendar_type );
					?>
					<label class="wdc-calendar-day <?php echo $day->working ? 'is-working' : 'is-non-working'; ?>">
						<input type="hidden" name="days[<?php echo esc_attr( $date_value ); ?>][working]" value="0">
						<input class="wdc-calendar-day-toggle" type="checkbox" name="days[<?php echo esc_attr( $date_value ); ?>][working]" value="1" <?php checked( $day->working ); ?>>
						<span class="wdc-calendar-day-number"><?php echo esc_html( $date->format( 'j' ) ); ?></span>
						<input class="wdc-calendar-reason" type="text" name="days[<?php echo esc_attr( $date_value ); ?>][reason]" value="<?php echo esc_attr( $day->reason ); ?>">
					</label>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function handle_post( string $calendar_type, int $year ): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return '';
		}

		$action = isset( $_POST['wdc_calendar_action'] ) ? sanitize_key( wp_unslash( $_POST['wdc_calendar_action'] ) ) : '';
		if ( 'generate' === $action ) {
			$this->repository->delete_year( $calendar_type, $year );
			$this->repository->save_days( $this->year_generator->generate_year( $calendar_type, $year ) );
			$this->calendar_service->mark_attention_resolved( $calendar_type, $year );
			return __( 'Год календаря сгенерирован.', 'walls-delivery-calc' );
		}

		if ( 'save' !== $action ) {
			return '';
		}

		$posted_days = isset( $_POST['days'] ) && is_array( $_POST['days'] ) ? wp_unslash( $_POST['days'] ) : array();
		$days        = array();
		foreach ( $posted_days as $date => $data ) {
			if ( ! is_array( $data ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
				continue;
			}

			$days[] = new CalendarDay(
				(string) $date,
				isset( $data['working'] ) && '1' === (string) $data['working'],
				isset( $data['reason'] ) ? sanitize_text_field( (string) $data['reason'] ) : 'manual',
				$calendar_type
			);
		}

		$this->repository->save_days( $days );
		$this->calendar_service->mark_attention_resolved( $calendar_type, $year );
		return __( 'Календарь сохранен.', 'walls-delivery-calc' );
	}

	private function requested_calendar_type(): string {
		$value = isset( $_REQUEST['calendar_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['calendar_type'] ) ) : CalendarTypes::CARRIER_RU;
		return CalendarTypes::is_valid( $value ) ? $value : CalendarTypes::CARRIER_RU;
	}

	private function requested_year(): int {
		$year = isset( $_REQUEST['year'] ) ? (int) $_REQUEST['year'] : (int) gmdate( 'Y' );
		return max( 2026, min( 2100, $year ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function calendar_labels(): array {
		return array(
			CalendarTypes::CARRIER_RU => 'РФ/ТК',
			CalendarTypes::SHOP       => 'Магазин',
		);
	}
}
