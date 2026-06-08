# Changelog

## [1.1.0] - 2026-06-08

Préparation pour le répertoire WordPress Plugin Directory.

### Changements

- Renommage du dossier plugin : `modelo-nettytowpimport/` → `modelo-netty-importer/`
- Ajout de `readme.txt` (format requis par le répertoire WordPress)
- Correction du `Plugin URI` dans l'en-tête du plugin principal
- Mise à jour du workflow CI (`release.yml`) pour produire le zip avec le nouveau nom
- Mise à jour de toutes les références d'outillage (PHPStan, PHPCS, PHPUnit, wp-env, composer)

## [1.0.0] - 2026-06-08

Version de lancement — première publication open source.

### Prérequis et tests

- WordPress 6.8+, PHP 8.3+
- Matrice de compatibilité validée : WP 6.8.5 / 6.9.4 / 7.0 × PHP 8.3 / 8.4 / 8.5
