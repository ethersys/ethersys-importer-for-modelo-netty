<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// Fallback: tests purement unitaires sans WP (fonctions pures seulement).
	// Définit les constantes minimales pour éviter le `defined('ABSPATH') || exit`
	// et les constantes plugin présentes dans les class files.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
	}
	define( 'MNTI_VERSION', '0.0.0-test' );
	define( 'MNTI_PATH', dirname( __DIR__ ) . '/modelo-netty-importer/' );
	define( 'MNTI_URL', 'http://localhost/' );
	define( 'MNTI_BASENAME', 'modelo-netty-importer/modelo-netty-importer.php' );

	require_once dirname( __DIR__ ) . '/vendor/autoload.php';

	// Stubs des fonctions WordPress utilisées par les classes testées.
	if ( ! function_exists( 'remove_accents' ) ) {
		function remove_accents( string $str ): string {
			$transliterated = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $str );
			return ( $transliterated !== false ) ? $transliterated : $str;
		}
	}

	return;
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugin_file = WP_PLUGIN_DIR . '/modelo-netty-importer/modelo-netty-importer.php';
		if ( ! file_exists( $plugin_file ) ) {
			$plugin_file = dirname( __DIR__ ) . '/modelo-netty-importer/modelo-netty-importer.php';
		}
		require $plugin_file;
	}
);

require $_tests_dir . '/includes/bootstrap.php';
