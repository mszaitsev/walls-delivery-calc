<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Services\LocationDatabaseBackupService;

defined( 'ABSPATH' ) || exit;

final class LocationDatabaseBackupAdmin {
	private const CREATE = 'wdc_locations_backup_create';
	private const RESTORE = 'wdc_locations_backup_restore';
	private const DOWNLOAD = 'wdc_download_export_gar_places_script';
	public const COMMAND = <<<'POWERSHELL'
pwsh -ExecutionPolicy Bypass -File "D:\FIAS\Export-GarPlaces.ps1" `
  -Archive "D:\FIAS\gar_xml_full.zip" `
  -OutCsv "D:\FIAS\out\gar_places.csv" `
  -IncludeOptionalCodes
POWERSHELL;

	public function __construct( private LocationDatabaseBackupService $backups, private PluginEnvironment $environment, private Logger $logger ) {
	}

	public function register(): void {
		add_action( 'admin_post_' . self::CREATE, array( $this, 'create' ) );
		add_action( 'admin_post_' . self::RESTORE, array( $this, 'restore' ) );
		add_action( 'admin_post_' . self::DOWNLOAD, array( $this, 'download' ) );
	}

	public function create(): void {
		$this->operate( self::CREATE );
	}

	public function restore(): void {
		$this->operate( self::RESTORE );
	}

	private function authorize( string $action, bool $post = true ): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ( $post && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Недостаточно прав или неверный метод запроса.', 'walls-delivery-calc' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private function operate( string $action ): void {
		$this->authorize( $action );
		try {
			$result = self::CREATE === $action ? $this->backups->create() : $this->backups->restore();
			$notice = self::CREATE === $action ? 'created' : 'restored';
			if ( ! empty( $result['warnings'] ) ) {
				$notice = 'warning';
			}
		} catch ( \Throwable $error ) {
			$this->logger->error( 'Location database backup admin operation failed.', array( 'action' => $action, 'error' => $error->getMessage() ) );
			$notice = match ( $error->getCode() ) { 409 => 'busy', 422 => 'schema', 423 => 'job', default => 'error' };
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'wdc-platform-locations', 'wdc_backup_notice' => $notice ), admin_url( 'admin.php' ) ), 303 );
		exit;
	}

	public function download(): void {
		$this->authorize( self::DOWNLOAD, false );
		$path = $this->environment->plugin_dir() . 'src/Export-GarPlaces.ps1';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Файл Export-GarPlaces.ps1 не найден.', 'walls-delivery-calc' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="Export-GarPlaces.ps1"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	public function render(): void {
		$messages = array(
			'created' => 'Резервная копия базы населенных пунктов создана.',
			'restored' => 'База населенных пунктов восстановлена из резервной копии.',
			'warning' => 'Операция завершена, но очистка временных таблиц или кешей требует проверки журнала.',
			'error' => 'Не удалось выполнить операцию с резервной копией базы населенных пунктов.',
			'schema' => 'Структура резервной копии отличается от текущей структуры таблицы. Автоматическое восстановление отменено.',
			'busy' => 'Операция базы населённых пунктов уже выполняется или блокировка недоступна.',
			'job' => 'Завершите или отмените текущую задачу базы населённых пунктов перед резервным копированием или восстановлением.',
		);
		$notice = isset( $_GET['wdc_backup_notice'] ) && is_string( $_GET['wdc_backup_notice'] ) ? $_GET['wdc_backup_notice'] : '';
		if ( isset( $messages[ $notice ] ) ) {
			$class = in_array( $notice, array( 'created', 'restored' ), true ) ? 'success' : ( 'warning' === $notice ? 'warning' : 'error' );
			echo '<div class="notice notice-' . esc_attr( $class ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
		$status = array();
		$status_error = false;
		try {
			$status = $this->backups->status();
		} catch ( \Throwable $error ) {
			$status_error = true;
			$this->logger->error( 'Unable to display location backup status.', array( 'error' => $error->getMessage() ) );
		}
		echo '<section><h2>' . esc_html__( 'Резервная копия базы населенных пунктов', 'walls-delivery-calc' ) . '</h2><p>';
		echo esc_html( $status_error ? 'Сведения о резервной копии недоступны.' : ( empty( $status ) ? 'Резервная копия ещё не создана.' : 'Резервная копия от: ' . $status['date'] ) );
		echo '</p><p class="description">' . esc_html__( 'Включает населённые пункты и связанные aliases. Регионы и carrier mappings не входят в эту копию.', 'walls-delivery-calc' ) . '</p>';
		$this->form( self::CREATE, 'Создать резервную копию' );
		$this->form( self::RESTORE, 'Восстановить из резервной копии', empty( $status ) );
		echo '</section>';
	}

	private function form( string $action, string $label, bool $disabled = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"';
		if ( self::RESTORE === $action ) {
			echo ' onsubmit="return window.confirm(\'' . esc_js( 'Рабочая таблица населенных пунктов будет заменена данными из резервной копии. Продолжить?' ) . '\');"';
		}
		echo '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<p><button type="submit" class="button"' . ( $disabled ? ' disabled' : '' ) . '>' . esc_html( $label ) . '</button></p></form>';
	}

	public function render_script(): void {
		$url = wp_nonce_url( add_query_arg( 'action', self::DOWNLOAD, admin_url( 'admin-post.php' ) ), self::DOWNLOAD );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Скачать Export-GarPlaces.ps1', 'walls-delivery-calc' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Скрипт преобразует полный XML-архив GAR/ФИАС в CSV для существующего импортера. Запустите в PowerShell 7:', 'walls-delivery-calc' ) . '</p>';
		echo '<pre><code>' . esc_html( self::COMMAND ) . '</code></pre>';
	}
}
