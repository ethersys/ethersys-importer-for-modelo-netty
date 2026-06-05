<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Fallback: tests purement unitaires sans WP (fonctions pures seulement)
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	return;
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugin_file = WP_PLUGIN_DIR . '/modelo-nettytowpimport/modelo-nettytowpimport.php';
		if ( ! file_exists( $plugin_file ) ) {
			$plugin_file = dirname( __DIR__ ) . '/modelo-nettytowpimport/modelo-nettytowpimport.php';
		}
		require $plugin_file;
	}
);

require $_tests_dir . '/includes/bootstrap.php';
