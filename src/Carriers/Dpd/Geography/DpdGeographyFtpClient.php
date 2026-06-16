<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyFtpClient {
	public function __construct(
		private DpdSettings $settings
	) {
	}

	/**
	 * @return array{success:bool,status:string,path:string,source_file:string,message:string}
	 */
	public function download_latest(): array {
		if ( ! $this->is_sftp_available() ) {
			return $this->warning( 'SFTP extension is not available. Use manual CSV upload.' );
		}
		if ( ! $this->settings->has_geography_ftp_password() ) {
			return $this->failure( 'DPD SFTP password is not configured. Upload GeographyNewDPD CSV manually or save the encrypted password.' );
		}

		$host = $this->settings->geography_ftp_host();
		$port = $this->settings->geography_ftp_port();
		$directory = '/' . trim( $this->settings->geography_ftp_remote_directory(), '/' );

		try {
			$connection = ssh2_connect( $host, $port );
			if ( false === $connection ) {
				return $this->failure( 'Unable to connect to DPD SFTP host.' );
			}
			if ( ! ssh2_auth_password( $connection, $this->settings->geography_ftp_username(), $this->settings->geography_ftp_password() ) ) {
				return $this->failure( 'Unable to authenticate to DPD SFTP.' );
			}
			$sftp = ssh2_sftp( $connection );
			if ( false === $sftp ) {
				return $this->failure( 'Unable to initialize DPD SFTP.' );
			}

			$base = 'ssh2.sftp://' . intval( $sftp ) . $directory;
			$files = scandir( $base );
			if ( ! is_array( $files ) ) {
				return $this->failure( 'Unable to list DPD SFTP directory.' );
			}

			$source_file = $this->latest_file( $files );
			if ( '' === $source_file ) {
				return $this->failure( 'GeographyNewDPD CSV was not found in DPD SFTP directory.' );
			}

			$tmp = $this->temp_file( $source_file );
			$input = fopen( $base . '/' . $source_file, 'rb' );
			$output = fopen( $tmp, 'wb' );
			if ( ! is_resource( $input ) || ! is_resource( $output ) ) {
				if ( is_resource( $input ) ) {
					fclose( $input );
				}
				if ( is_resource( $output ) ) {
					fclose( $output );
				}
				@unlink( $tmp );
				return $this->failure( 'Unable to open DPD geography streams.' );
			}
			stream_copy_to_stream( $input, $output );
			fclose( $input );
			fclose( $output );

			return array( 'success' => true, 'status' => 'ok', 'path' => $tmp, 'source_file' => $source_file, 'message' => 'DPD geography CSV downloaded.' );
		} catch ( Throwable $throwable ) {
			return $this->failure( 'DPD SFTP download failed: ' . $throwable->getMessage() );
		}
	}

	public function is_sftp_available(): bool {
		return extension_loaded( 'ssh2' ) && function_exists( 'ssh2_connect' );
	}

	/**
	 * @param array<int,string> $files
	 */
	private function latest_file( array $files ): string {
		$candidates = array();
		foreach ( $files as $file ) {
			if ( preg_match( '/^GeographyNewDPD_(\d{4})_(\d{2})_(\d{2})\.csv$/', $file, $m ) ) {
				$candidates[ $m[1] . $m[2] . $m[3] ] = $file;
			}
		}
		if ( array() === $candidates ) {
			return '';
		}
		krsort( $candidates );
		return (string) reset( $candidates );
	}

	private function temp_file( string $source_file ): string {
		if ( function_exists( 'wp_tempnam' ) ) {
			$tmp = wp_tempnam( $source_file );
			if ( is_string( $tmp ) && '' !== $tmp ) {
				return $tmp;
			}
		}

		return tempnam( sys_get_temp_dir(), 'wdc-dpd-geography-' ) ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid( 'wdc-dpd-geography-', true );
	}

	/**
	 * @return array{success:bool,status:string,path:string,source_file:string,message:string}
	 */
	private function failure( string $message ): array {
		return array( 'success' => false, 'status' => 'failed', 'path' => '', 'source_file' => '', 'message' => $message );
	}

	/**
	 * @return array{success:bool,status:string,path:string,source_file:string,message:string}
	 */
	private function warning( string $message ): array {
		return array( 'success' => false, 'status' => 'warning', 'path' => '', 'source_file' => '', 'message' => $message );
	}
}
