<?php
/**
 * Diagnostic script to verify direct DB connectivity for integration tests.
 *
 * Not a PHPUnit test. Run it manually only when you need to validate the
 * database credentials used for the WordPress integration suite.
 *
 * @package RagInteractionLoggerMonitor
 */

$required_variables = array(
	'RILM_TEST_DB_HOST',
	'RILM_TEST_DB_PORT',
	'RILM_TEST_DB_USER',
	'RILM_TEST_DB_PASSWORD',
	'RILM_TEST_DB_NAME',
);

foreach ( $required_variables as $required_variable ) {
	if ( false === getenv( $required_variable ) || '' === getenv( $required_variable ) ) {
		fwrite( STDERR, "Missing required environment variable: {$required_variable}\n" );
		exit( 1 );
	}
}

$db_host     = getenv( 'RILM_TEST_DB_HOST' );
$db_port     = (int) getenv( 'RILM_TEST_DB_PORT' );
$db_user     = getenv( 'RILM_TEST_DB_USER' );
$db_password = getenv( 'RILM_TEST_DB_PASSWORD' );
$db_name     = getenv( 'RILM_TEST_DB_NAME' );

$link = mysqli_connect( $db_host, $db_user, $db_password, $db_name, $db_port );

if ( ! $link ) {
	fwrite( STDERR, "Database connection failed. Check the dedicated test database configuration.\n" );
	exit( 1 );
}

echo "Database connection successful.\n";
mysqli_close( $link );
