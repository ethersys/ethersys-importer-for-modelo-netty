<?php

declare(strict_types=1);

// Chemin WordPress dans le container wp-env Docker.
$wp_load = '/var/www/html/wp-load.php';

if (! file_exists($wp_load)) {
    fprintf(
        STDERR,
        "ERREUR : wp-load.php introuvable à %s.\n" .
        "Exécuter les tests d'intégration via : wp-env run cli bash -c \"...\"\n",
        $wp_load
    );
    exit(1);
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require $wp_load;

// S'assurer que les tables custom existent (normalement créées à l'activation).
\Ethersys\NettyImport\Db::create_tables();

// Constante pour les fixtures (chemin dans le container Docker).
define('EIMN_FIXTURE_DIR', '/var/www/html/wp-content/mnti-tests/Fixtures');
