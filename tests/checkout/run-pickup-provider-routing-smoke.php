<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$script = $root . '/tests/checkout/run-pickup-provider-routing-smoke.js';
$command = 'node ' . escapeshellarg( $script );
passthru( $command, $exit_code );

exit( (int) $exit_code );
