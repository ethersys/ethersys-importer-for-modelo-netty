<?php

declare(strict_types=1);

// Constantes WP minimales pour que defined('ABSPATH') || exit passe.
define('ABSPATH', sys_get_temp_dir() . '/');
define('EIMN_VERSION', 'test');

// Résolution du chemin plugin : fonctionne sur l'hôte et dans le container Docker wp-env.
// Hôte  : test/phpunit/Unit/../../../ethersys-importer-for-modelo-netty
// Docker: mnti-tests/Unit/../../plugins/ethersys-importer-for-modelo-netty
$_eimn_candidate = realpath(__DIR__ . '/../../../ethersys-importer-for-modelo-netty');
if (! $_eimn_candidate || ! is_dir($_eimn_candidate)) {
    // Chemin Docker wp-env : le plugin est dans wp-content/plugins/
    $_eimn_candidate = realpath(__DIR__ . '/../../plugins/ethersys-importer-for-modelo-netty');
}
if (! $_eimn_candidate || ! is_dir($_eimn_candidate)) {
    fprintf(STDERR, "ERREUR : impossible de localiser le répertoire du plugin.\n");
    exit(1);
}
define('EIMN_PATH', $_eimn_candidate . '/');

require __DIR__ . '/../vendor/autoload.php';

// Charger les classes du plugin à tester.
require EIMN_PATH . 'includes/class-xml-parser.php';
require EIMN_PATH . 'includes/class-importer.php';
