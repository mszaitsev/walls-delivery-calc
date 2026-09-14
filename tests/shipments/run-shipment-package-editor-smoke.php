<?php
declare(strict_types=1);

$script = __DIR__ . '/run-shipment-package-editor-smoke.js';
$output = array();
$code = 0;
exec( 'node ' . escapeshellarg( $script ) . ' 2>&1', $output, $code );
if ( 0 !== $code ) {
	throw new RuntimeException( "Shipment package editor JS smoke failed:\n" . implode( "\n", $output ) );
}

echo implode( "\n", $output ) . "\n";
