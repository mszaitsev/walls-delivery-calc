<?php
declare(strict_types=1);

final class ShipmentRegressionRunner {
	public const EXIT_SUCCESS = 0;
	public const EXIT_FAILURE = 1;
	public const EXIT_CONFIG = 2;
	public const EXIT_INFRASTRUCTURE = 3;

	private string $root;

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private array $manifest;

	/**
	 * @var null|callable(string,int):array<string,mixed>
	 */
	private $process_executor;

	/**
	 * @param array<string,array<string,mixed>> $manifest
	 */
	public function __construct( string $root, array $manifest, ?callable $process_executor = null ) {
		$this->root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->manifest = $this->normalize_manifest( $manifest );
		$this->process_executor = $process_executor;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function load_manifest( string $path ): array {
		$manifest = require $path;
		if ( ! is_array( $manifest ) ) {
			throw new InvalidArgumentException( 'Regression profile manifest must return an array.' );
		}
		return $manifest;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array{exit_code:int,results:array<int,array<string,mixed>>,counts:array<string,int>,duration:float}
	 */
	public function run( array $options = array() ): array {
		$started = microtime( true );
		$tests = $this->select_tests( $options );
		$results = array();
		$exit_code = self::EXIT_SUCCESS;

		foreach ( $tests as $entry ) {
			$result = $this->run_entry( $entry );
			$results[] = $result;

			if ( in_array( $result['status'], array( 'TIMEOUT', 'INFRASTRUCTURE' ), true ) ) {
				$exit_code = max( $exit_code, self::EXIT_INFRASTRUCTURE );
			} elseif ( in_array( $result['status'], array( 'FAIL', 'BASELINE-MISMATCH' ), true ) ) {
				$exit_code = max( $exit_code, self::EXIT_FAILURE );
			}

			if ( ! empty( $options['fail_fast'] ) && self::EXIT_SUCCESS !== $exit_code ) {
				break;
			}
		}

		return array(
			'exit_code' => $exit_code,
			'results' => $results,
			'counts' => $this->counts_for( $results, $options ),
			'duration' => microtime( true ) - $started,
		);
	}

	/**
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function groups(): array {
		$groups = array();
		foreach ( $this->manifest as $entry ) {
			foreach ( $entry['groups'] as $group ) {
				$groups[ $group ][] = $entry;
			}
		}
		ksort( $groups );
		return $groups;
	}

	public function print_list(): void {
		foreach ( $this->groups() as $group => $entries ) {
			echo '[' . $group . "]\n";
			foreach ( $entries as $entry ) {
				$flags = array();
				if ( $entry['baseline'] ) {
					$flags[] = 'baseline';
				}
				if ( $entry['optional'] ) {
					$flags[] = 'optional';
				}
				if ( $entry['required'] ) {
					$flags[] = 'required';
				}
				echo '  ' . $entry['id'] . ' -> ' . $entry['path'];
				if ( array() !== $flags ) {
					echo ' (' . implode( ', ', $flags ) . ')';
				}
				echo "\n";
			}
		}
	}

	/**
	 * @param array<string,mixed> $run
	 */
	public function print_report( array $run ): void {
		foreach ( $run['results'] as $result ) {
			$line = '[' . $result['status'] . '] ' . $result['group'] . ' / ' . $result['id'] . ' (' . number_format( (float) $result['duration'], 2, '.', '' ) . 's)';
			if ( '' !== $result['summary'] ) {
				$line .= ' - ' . $result['summary'];
			}
			echo $line . "\n";
			if ( in_array( $result['status'], array( 'FAIL', 'TIMEOUT', 'INFRASTRUCTURE', 'BASELINE-MISMATCH' ), true ) ) {
				echo 'Exit code: ' . $result['exit_code'] . "\n";
				$this->print_output_block( 'STDOUT', $result['stdout'] );
				$this->print_output_block( 'STDERR', $result['stderr'] );
			}
			if ( 'BASELINE-RESOLVED' === $result['status'] ) {
				echo "KNOWN BASELINE NOW PASSES - remove/update baseline entry.\n";
			}
		}

		$counts = $run['counts'];
		echo "\nSummary:\n";
		echo 'Passed: ' . $counts['passed'] . "\n";
		echo 'Failed: ' . $counts['failed'] . "\n";
		echo 'Baseline: ' . $counts['baseline'] . "\n";
		echo 'Baseline resolved: ' . $counts['baseline_resolved'] . "\n";
		echo 'Skipped: ' . $counts['skipped'] . "\n";
		echo 'Timeout: ' . $counts['timeout'] . "\n";
		echo 'Infrastructure: ' . $counts['infrastructure'] . "\n";
		echo 'Duration: ' . number_format( (float) $run['duration'], 2, '.', '' ) . "s\n";
	}

	/**
	 * @param array<string,mixed> $manifest
	 * @return array<string,array<string,mixed>>
	 */
	private function normalize_manifest( array $manifest ): array {
		$normalized = array();
		$default_paths = array();

		foreach ( $manifest as $id => $entry ) {
			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				throw new InvalidArgumentException( 'Regression profile test id must be a non-empty string.' );
			}
			if ( isset( $normalized[ $id ] ) ) {
				throw new InvalidArgumentException( 'Duplicate regression profile test id: ' . $id );
			}
			if ( ! is_array( $entry ) ) {
				throw new InvalidArgumentException( 'Regression profile entry must be an array: ' . $id );
			}

			$path = str_replace( '\\', '/', (string) ( $entry['path'] ?? '' ) );
			$groups = array_values( array_unique( array_map( 'strval', (array) ( $entry['groups'] ?? array() ) ) ) );
			$groups = array_values( array_filter( $groups, static fn( string $group ): bool => '' !== trim( $group ) ) );
			$required = (bool) ( $entry['required'] ?? true );
			$baseline = (bool) ( $entry['baseline'] ?? false );
			$optional = (bool) ( $entry['optional'] ?? false );
			$timeout = (int) ( $entry['timeout'] ?? 90 );

			if ( '' === $path || str_contains( $path, '..' ) || ! str_starts_with( $path, 'tests/' ) || ! str_ends_with( $path, '.php' ) ) {
				throw new InvalidArgumentException( 'Invalid regression profile path for ' . $id . ': ' . $path );
			}
			$absolute = $this->absolute_path( $path );
			if ( ! is_file( $absolute ) ) {
				throw new InvalidArgumentException( 'Regression profile test file does not exist for ' . $id . ': ' . $path );
			}
			if ( array() === $groups ) {
				throw new InvalidArgumentException( 'Regression profile groups must not be empty for ' . $id );
			}
			if ( $timeout <= 0 ) {
				throw new InvalidArgumentException( 'Regression profile timeout must be positive for ' . $id );
			}
			if ( $baseline && '' === (string) ( $entry['expected_failure'] ?? '' ) ) {
				throw new InvalidArgumentException( 'Baseline entry must define expected_failure: ' . $id );
			}
			if ( $baseline && $required ) {
				throw new InvalidArgumentException( 'Baseline entry must not be required: ' . $id );
			}

			if ( $required && ! $baseline && ! $optional ) {
				if ( isset( $default_paths[ $path ] ) ) {
					throw new InvalidArgumentException( 'Duplicate mandatory regression profile path: ' . $path );
				}
				$default_paths[ $path ] = true;
			}

			$normalized[ $id ] = array(
				'id' => $id,
				'label' => (string) ( $entry['label'] ?? $id ),
				'path' => $path,
				'absolute_path' => $absolute,
				'groups' => $groups,
				'required' => $required,
				'baseline' => $baseline,
				'optional' => $optional,
				'expected_failure' => (string) ( $entry['expected_failure'] ?? '' ),
				'timeout' => $timeout,
			);
		}

		return $normalized;
	}

	private function absolute_path( string $path ): string {
		return $this->root . '/' . ltrim( $path, '/' );
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<int,array<string,mixed>>
	 */
	private function select_tests( array $options ): array {
		$selected = array();
		foreach ( $this->entries_in_scope( $options ) as $entry ) {
			if ( $entry['baseline'] && empty( $options['include_baseline'] ) ) {
				continue;
			}
			if ( $entry['optional'] && empty( $options['include_optional'] ) ) {
				continue;
			}
			if ( ! $entry['required'] && ! $entry['baseline'] && ! $entry['optional'] ) {
				continue;
			}
			$selected[] = $entry;
		}
		return $selected;
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<int,array<string,mixed>>
	 */
	private function entries_in_scope( array $options ): array {
		$group = isset( $options['group'] ) ? (string) $options['group'] : '';
		if ( '' !== $group && ! isset( $this->groups()[ $group ] ) ) {
			throw new InvalidArgumentException( 'Unknown regression profile group: ' . $group );
		}

		if ( '' === $group ) {
			return array_values( $this->manifest );
		}

		return array_values(
			array_filter(
				$this->manifest,
				static fn( array $entry ): bool => in_array( $group, $entry['groups'], true )
			)
		);
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private function run_entry( array $entry ): array {
		$started = microtime( true );
		$process = $this->process_executor
			? $this->normalize_process_result( ( $this->process_executor )( $entry['absolute_path'], (int) $entry['timeout'] ) )
			: $this->run_process( $entry['absolute_path'], (int) $entry['timeout'] );
		$duration = microtime( true ) - $started;
		$output = trim( $process['stdout'] . "\n" . $process['stderr'] );
		$status = 'PASS';
		$summary = $this->last_non_empty_line( $process['stdout'] );

		if ( $process['timed_out'] ) {
			$status = 'TIMEOUT';
			$summary = 'Process exceeded timeout ' . $entry['timeout'] . 's.';
		} elseif ( $process['infrastructure_error'] ) {
			$status = 'INFRASTRUCTURE';
			$summary = $this->last_non_empty_line( $process['stderr'] );
			if ( '' === $summary ) {
				$summary = 'Process infrastructure failure.';
			}
		} elseif ( $entry['baseline'] || ( $entry['optional'] && '' !== $entry['expected_failure'] ) ) {
			if ( 0 === $process['exit_code'] ) {
				$status = 'BASELINE-RESOLVED';
				$summary = 'Known baseline now passes.';
			} elseif ( str_contains( $output, $entry['expected_failure'] ) ) {
				$status = 'BASELINE';
				$summary = $entry['expected_failure'];
			} else {
				$status = 'BASELINE-MISMATCH';
				$summary = 'Expected baseline signature not found: ' . $entry['expected_failure'];
			}
		} elseif ( 0 !== $process['exit_code'] ) {
			$status = 'FAIL';
			$summary = $this->last_non_empty_line( $output );
		}

		return array(
			'id' => $entry['id'],
			'group' => $entry['groups'][0],
			'path' => $entry['path'],
			'status' => $status,
			'summary' => $summary,
			'exit_code' => $process['exit_code'],
			'stdout' => $process['stdout'],
			'stderr' => $process['stderr'],
			'duration' => $duration,
		);
	}

	/**
	 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,infrastructure_error:bool}
	 */
	private function run_process( string $file, int $timeout ): array {
		$command = array( PHP_BINARY, $file );
		$stdout_file = tempnam( sys_get_temp_dir(), 'wdc-regression-out-' );
		$stderr_file = tempnam( sys_get_temp_dir(), 'wdc-regression-err-' );
		if ( false === $stdout_file || false === $stderr_file ) {
			if ( is_string( $stdout_file ) ) {
				@unlink( $stdout_file );
			}
			if ( is_string( $stderr_file ) ) {
				@unlink( $stderr_file );
			}
			return array( 'exit_code' => self::EXIT_INFRASTRUCTURE, 'stdout' => '', 'stderr' => 'Unable to create temporary output files.', 'timed_out' => false, 'infrastructure_error' => true );
		}
		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $stdout_file, 'w' ),
			2 => array( 'file', $stderr_file, 'w' ),
		);
		$process = false;
		$last_error = null;
		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$process = @proc_open( $command, $descriptor_spec, $pipes, $this->root );
			if ( is_resource( $process ) ) {
				break;
			}
			$last_error = error_get_last();
			usleep( 200000 );
		}
		if ( ! is_resource( $process ) ) {
			@unlink( $stdout_file );
			@unlink( $stderr_file );
			$message = is_array( $last_error ) && isset( $last_error['message'] ) ? (string) $last_error['message'] : 'Unable to start PHP process.';
			return array( 'exit_code' => self::EXIT_INFRASTRUCTURE, 'stdout' => '', 'stderr' => $message, 'timed_out' => false, 'infrastructure_error' => true );
		}
		fclose( $pipes[0] );

		$deadline = microtime( true ) + $timeout;
		$timed_out = false;

		while ( true ) {
			$status = proc_get_status( $process );
			if ( empty( $status['running'] ) ) {
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				$this->terminate_process( $process, (int) ( $status['pid'] ?? 0 ) );
				usleep( 100000 );
				break;
			}
			usleep( 10000 );
		}

		$stdout = is_file( $stdout_file ) ? (string) file_get_contents( $stdout_file ) : '';
		$stderr = is_file( $stderr_file ) ? (string) file_get_contents( $stderr_file ) : '';
		@unlink( $stdout_file );
		@unlink( $stderr_file );
		if ( $timed_out ) {
			return array(
				'exit_code' => self::EXIT_INFRASTRUCTURE,
				'stdout' => $stdout,
				'stderr' => $stderr,
				'timed_out' => true,
				'infrastructure_error' => false,
			);
		}
		$exit_code = proc_close( $process );

		return array(
			'exit_code' => (int) $exit_code,
			'stdout' => $stdout,
			'stderr' => $stderr,
			'timed_out' => $timed_out,
			'infrastructure_error' => false,
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool,infrastructure_error:bool}
	 */
	private function normalize_process_result( array $result ): array {
		if ( ! array_key_exists( 'exit_code', $result ) ) {
			return array(
				'exit_code' => self::EXIT_INFRASTRUCTURE,
				'stdout' => (string) ( $result['stdout'] ?? '' ),
				'stderr' => 'Invalid process executor result.',
				'timed_out' => false,
				'infrastructure_error' => true,
			);
		}

		return array(
			'exit_code' => (int) $result['exit_code'],
			'stdout' => (string) ( $result['stdout'] ?? '' ),
			'stderr' => (string) ( $result['stderr'] ?? '' ),
			'timed_out' => (bool) ( $result['timed_out'] ?? false ),
			'infrastructure_error' => (bool) ( $result['infrastructure_error'] ?? false ),
		);
	}

	private function terminate_process( mixed $process, int $pid ): void {
		if ( is_resource( $process ) ) {
			@proc_terminate( $process, 9 );
		}
		if ( 'Windows' === PHP_OS_FAMILY && $pid > 0 ) {
			@exec( 'taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL' );
			return;
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $results
	 * @param array<string,mixed> $options
	 * @return array<string,int>
	 */
	private function counts_for( array $results, array $options ): array {
		$counts = array(
			'passed' => 0,
			'failed' => 0,
			'baseline' => 0,
			'baseline_resolved' => 0,
			'skipped' => 0,
			'timeout' => 0,
			'infrastructure' => 0,
		);
		foreach ( $results as $result ) {
			match ( $result['status'] ) {
				'PASS' => $counts['passed']++,
				'BASELINE' => $counts['baseline']++,
				'BASELINE-RESOLVED' => $counts['baseline_resolved']++,
				'TIMEOUT' => $counts['timeout']++,
				'INFRASTRUCTURE' => $counts['infrastructure']++,
				'FAIL', 'BASELINE-MISMATCH' => $counts['failed']++,
				default => null,
			};
		}
		foreach ( $this->entries_in_scope( $options ) as $entry ) {
			if ( $entry['baseline'] && empty( $options['include_baseline'] ) ) {
				$counts['skipped']++;
			}
			if ( $entry['optional'] && empty( $options['include_optional'] ) ) {
				$counts['skipped']++;
			}
		}
		return $counts;
	}

	private function last_non_empty_line( string $output ): string {
		$lines = array_reverse( preg_split( '/\R/', trim( $output ) ) ?: array() );
		foreach ( $lines as $line ) {
			if ( '' !== trim( $line ) ) {
				return trim( $line );
			}
		}
		return '';
	}

	private function print_output_block( string $label, string $output ): void {
		echo $label . ":\n";
		$trimmed = trim( $output );
		echo ( '' === $trimmed ? '(empty)' : $trimmed ) . "\n";
	}
}
