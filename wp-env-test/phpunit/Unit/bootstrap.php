<?php

declare(strict_types=1);

// Constantes WP minimales pour que defined('ABSPATH') || exit passe.
define('ABSPATH', sys_get_temp_dir() . '/');
define('MNTI_VERSION', 'test');

// Résolution du chemin plugin : fonctionne sur l'hôte et dans le container Docker wp-env.
// Hôte  : test/phpunit/Unit/../../../modelo-netty-importer
// Docker: mnti-tests/Unit/../../plugins/modelo-netty-importer
$_mnti_candidate = realpath(__DIR__ . '/../../../modelo-netty-importer');
if (! $_mnti_candidate || ! is_dir($_mnti_candidate)) {
    // Chemin Docker wp-env : le plugin est dans wp-content/plugins/
    $_mnti_candidate = realpath(__DIR__ . '/../../plugins/modelo-netty-importer');
}
if (! $_mnti_candidate || ! is_dir($_mnti_candidate)) {
    fprintf(STDERR, "ERREUR : impossible de localiser le répertoire du plugin.\n");
    exit(1);
}
define('MNTI_PATH', $_mnti_candidate . '/');

require __DIR__ . '/../vendor/autoload.php';

// Charger les classes du plugin à tester.
require MNTI_PATH . 'includes/class-xml-parser.php';
require MNTI_PATH . 'includes/class-importer.php';
