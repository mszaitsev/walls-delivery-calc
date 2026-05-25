<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );

function no_legacy_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

no_legacy_smoke_assert( ! is_dir( $root . '/includes' ), 'Legacy includes directory must not exist.' );
no_legacy_smoke_assert( ! is_dir( $root . '/database/demo' ), 'Runtime database/demo directory must not exist.' );
no_legacy_smoke_assert( is_readable( $root . '/walls-delivery-calc.php' ), 'Main plugin file must exist.' );

$runtime_paths = array(
	$root . '/walls-delivery-calc.php',
	$root . '/src',
	$root . '/database/migrations',
);

$forbidden = array(
	'/require(?:_once)?\s+[^;]*(?:WDC_PLUGIN_DIR|plugin_dir_path|__DIR__|dirname)[^;]*[\/\\\\]includes[\/\\\\]/i' => 'runtime require/include must not load plugin includes/*',
	'/class_exists\s*\(\s*[\'"]\\\\?WDC_/i' => 'runtime must not probe WDC_* legacy classes',
	'/new\s+\\\\?WDC_[A-Za-z0-9_]+/i' => 'runtime must not instantiate WDC_* legacy classes',
	'/WDC_Plugin|WDC_Shipping_Method|WDC_Russian_Post|WDC_Settings|WDC_Cache|WDC_Logger|WDC_Order_Meta|WDC_Weight_Calculator|WDC_Quote_Normalizer/' => 'runtime must not reference removed WDC_* classes',
	'/database\/demo|database\\\\demo/i' => 'runtime must not reference database/demo fixtures',
);

$files = array();
foreach ( $runtime_paths as $path ) {
	if ( is_file( $path ) ) {
		$files[] = $path;
		continue;
	}

	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

foreach ( $files as $file ) {
	$contents = (string) file_get_contents( $file );
	foreach ( $forbidden as $pattern => $message ) {
		no_legacy_smoke_assert( 1 !== preg_match( $pattern, $contents ), $message . ': ' . str_replace( $root . DIRECTORY_SEPARATOR, '', $file ) );
	}
}

$main = (string) file_get_contents( $root . '/walls-delivery-calc.php' );
no_legacy_smoke_assert( str_contains( $main, "require_once WDC_PLUGIN_DIR . 'src/Core/bootstrap.php'" ), 'Main plugin file must load src bootstrap.' );
no_legacy_smoke_assert( ! str_contains( $main, 'wdc_plugin()' ), 'Main plugin file must not bootstrap legacy singleton.' );

echo "No legacy runtime smoke test passed.\n";
