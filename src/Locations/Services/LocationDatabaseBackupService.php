<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use DateTimeImmutable;
use RuntimeException;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Storage\LocationWriteLock;

defined( 'ABSPATH' ) || exit;

final class LocationDatabaseBackupService {
	private const TABLES = array( 'wdc_locations' );
	private \wpdb $db;

	public function __construct(
		private LocationWriteLock $lock,
		private LocationCountryIndexService $countries,
		private DeliveryQuoteCacheManager $quote_cache,
		private Logger $logger,
		?\wpdb $db = null
	) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/** @return array<string,array<int,string>> */
	private function snapshots(): array {
		$sets = array();
		foreach ( self::TABLES as $base ) {
			$prefix = $this->db->prefix . $base . '_backup_';
			$names = $this->db->get_col( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $prefix ) . '%' ) );
			if ( ! is_array( $names ) || '' !== $this->db->last_error ) {
				$this->fail( 'Unable to discover location backups.' );
			}
			foreach ( $names as $name ) {
				if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d{8}_\d{6})$/D', (string) $name, $match ) ) {
					$date = DateTimeImmutable::createFromFormat( '!Ymd_His', $match[1] );
					if ( false !== $date && $date->format( 'Ymd_His' ) === $match[1] ) {
						$sets[ $match[1] ][ $base ] = (string) $name;
					}
				}
			}
		}
		$result = array();
		foreach ( $sets as $stamp => $pair ) {
			if ( isset( $pair[ self::TABLES[0] ] ) ) {
				$result[ $stamp ] = array( $pair[ self::TABLES[0] ] );
			}
		}
		krsort( $result, SORT_STRING );
		return $result;
	}

	/** @return array{timestamp:string,date:string}|array{} */
	public function status(): array {
		$stamp = array_key_first( $this->snapshots() );
		if ( null === $stamp ) {
			return array();
		}
		return array( 'timestamp' => $stamp, 'date' => DateTimeImmutable::createFromFormat( '!Ymd_His', $stamp )->format( 'd.m.Y H:i' ) );
	}

	/** @return array{warnings:array<int,string>} */
	public function create(): array {
		return $this->lock->run( function (): array {
			$this->assert_no_active_job();
			$old = $this->snapshots();
			$stamp = current_datetime()->format( 'Ymd_His' );
			$live = $this->names();
			$temp = $this->names( '_backup_tmp_' . $stamp );
			$backup = $this->names( '_backup_' . $stamp );
			$this->assert_absent( array_merge( $temp, $backup ) );
			$created = array();
			try {
				foreach ( $live as $index => $table ) {
					$this->copy( $table, $temp[ $index ], $created );
				}
				$this->rename( array( $temp[0] => $backup[0] ) );
			} catch ( \Throwable $error ) {
				$this->cleanup( $created );
				throw $error;
			}
			$warnings = array();
			foreach ( $old as $pair ) {
				$warnings = array_merge( $warnings, $this->cleanup( $pair ) );
			}
			return array( 'warnings' => $warnings );
		} );
	}

	/** @return array{warnings:array<int,string>} */
	public function restore(): array {
		return $this->lock->run( function (): array {
			$this->assert_no_active_job();
			$snapshots = $this->snapshots();
			$backup = reset( $snapshots );
			if ( false === $backup ) {
				throw new RuntimeException( 'Резервная копия ещё не создана.' );
			}
			$live = $this->names();
			foreach ( $live as $index => $table ) {
				if ( $this->schema( $table ) !== $this->schema( $backup[ $index ] ) ) {
					throw new RuntimeException( 'Структура резервной копии отличается от текущей структуры таблицы. Автоматическое восстановление отменено.', 422 );
				}
			}
			$stamp = current_datetime()->format( 'Ymd_His' );
			$temp = $this->names( '_restore_tmp_' . $stamp );
			$old = $this->names( '_restore_old_' . $stamp );
			$this->assert_absent( array_merge( $temp, $old ) );
			$created = array();
			try {
				foreach ( $backup as $index => $table ) {
					$this->copy( $table, $temp[ $index ], $created );
				}
				$this->rename( array( $live[0] => $old[0], $temp[0] => $live[0] ) );
			} catch ( \Throwable $error ) {
				$this->cleanup( $created );
				throw $error;
			}
			$warnings = $this->cleanup( $old );
			try {
				$this->countries->mark_stale();
				$this->quote_cache->clear_all_delivery_cache();
			} catch ( \Throwable $error ) {
				$this->logger->error( 'Location snapshot restored but cache invalidation failed.', array( 'error' => $error->getMessage() ) );
				$warnings[] = 'Не удалось полностью обновить кеши доставки.';
			}
			return array( 'warnings' => $warnings );
		} );
	}

	private function assert_no_active_job(): void {
		( new LocationMaintenanceJobGuard() )->assert_no_active_jobs();
	}

	/** @return array<int,string> */
	private function names( string $suffix = '' ): array {
		return array_map( fn( string $table ): string => $this->db->prefix . $table . $suffix, self::TABLES );
	}

	private function identifier( string $name ): string {
		$prefix = preg_quote( $this->db->prefix, '/' );
		if ( strlen( $name ) > 64 || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $name ) || ! preg_match( '/^' . $prefix . 'wdc_locations(?:_(?:backup|backup_tmp|restore_tmp|restore_old)_\d{8}_\d{6})?$/D', $name ) ) {
			throw new RuntimeException( 'Недопустимое внутреннее имя таблицы.' );
		}
		return '`' . $name . '`';
	}

	private function exists( string $table ): bool {
		$this->identifier( $table );
		$found = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
		if ( '' !== $this->db->last_error ) {
			$this->fail( 'Unable to inspect table.' );
		}
		return $table === $found;
	}

	private function assert_absent( array $tables ): void {
		foreach ( $tables as $table ) {
			if ( $this->exists( $table ) ) {
				throw new RuntimeException( 'Таблицы операции уже существуют. Повторите позже.' );
			}
		}
	}

	private function copy( string $source, string $target, array &$created ): void {
		$schema = $this->schema( $source, false );
		$this->query( 'CREATE TABLE ' . $this->identifier( $target ) . ' LIKE ' . $this->identifier( $source ) );
		$created[] = $target;
		$this->query( 'INSERT INTO ' . $this->identifier( $target ) . ' SELECT * FROM ' . $this->identifier( $source ) );
		if ( preg_match( '/ AUTO_INCREMENT=(\d+)\b/', $schema, $match ) ) {
			$this->query( 'ALTER TABLE ' . $this->identifier( $target ) . ' AUTO_INCREMENT=' . $match[1] );
		}
		if ( ! $this->exists( $target ) || $this->count( $source ) !== $this->count( $target ) ) {
			$this->fail( 'Location snapshot row counts differ.' );
		}
	}

	private function count( string $table ): int {
		$count = $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->identifier( $table ) );
		if ( null === $count || '' !== $this->db->last_error || ! ctype_digit( (string) $count ) ) {
			$this->fail( 'Unable to count location snapshot rows.' );
		}
		return (int) $count;
	}

	private function schema( string $table, bool $normalize = true ): string {
		$row = $this->db->get_row( 'SHOW CREATE TABLE ' . $this->identifier( $table ), ARRAY_N );
		if ( ! is_array( $row ) || empty( $row[1] ) || '' !== $this->db->last_error ) {
			$this->fail( 'Unable to read location table schema.' );
		}
		$schema = (string) $row[1];
		// CREATE LIKE cannot preserve foreign keys or triggers; refuse custom dependencies.
		$dependencies = $this->db->get_var( $this->db->prepare(
			'SELECT (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = %s) + (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = %s)', $table, $table
		) );
		if ( null === $dependencies || '' !== $this->db->last_error || (int) $dependencies > 0 || str_contains( strtoupper( $schema ), 'FOREIGN KEY' ) ) {
			$this->fail( 'Unsupported location table dependencies or inaccessible schema metadata.' );
		}
		if ( ! $normalize ) {
			return $schema;
		}
		$schema = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `snapshot`', $schema ) ?? $schema;
		return preg_replace( '/ AUTO_INCREMENT=\d+\b/', '', $schema ) ?? $schema;
	}

	private function rename( array $pairs ): void {
		$parts = array();
		foreach ( $pairs as $from => $to ) {
			$parts[] = $this->identifier( $from ) . ' TO ' . $this->identifier( $to );
		}
		$this->query( 'RENAME TABLE ' . implode( ', ', $parts ) );
	}

	/** @return array<int,string> */
	private function cleanup( array $tables ): array {
		$warnings = array();
		foreach ( $tables as $table ) {
			if ( false === $this->db->query( 'DROP TABLE IF EXISTS ' . $this->identifier( $table ) ) ) {
				$this->logger->warning( 'Unable to remove old/temporary location snapshot table.', array( 'table' => $table, 'error' => $this->db->last_error ) );
				$warnings[] = 'Не удалось удалить одну из старых или временных таблиц.';
			}
		}
		return $warnings;
	}

	private function query( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			$this->fail( 'Location snapshot database operation failed.' );
		}
	}

	private function fail( string $message ): never {
		$this->logger->error( $message, array( 'error' => $this->db->last_error ) );
		throw new RuntimeException( 'Не удалось выполнить операцию с резервной копией базы населённых пунктов. Подробности в журнале.' );
	}
}
