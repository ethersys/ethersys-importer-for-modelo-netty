# Changelog

## [1.2.1] - 2026-06-10

### Corrections

- **Plugin URI** : corrigé dans l'en-tête PHP (URL GitHub incorrecte après le renommage 1.2.0)
- **`wp-env-test/.wp-env.json`** : core WordPress épinglé à `6.8.5`, suppression de `WP_DEBUG` / `WP_DEBUG_LOG` de la config

## [1.2.0] - 2026-06-10

Renommage complet du projet et correction de sécurité.

### Changements

- **Renommage** : nom affiché `Modelo Netty Importer` → `Ethersys Importer For Modelo Netty`
- **Slug/dossier** : `modelo-netty-importer/` → `ethersys-importer-for-modelo-netty/`
- **Namespace PHP** : `Modelo\NettyImport` → `Ethersys\NettyImport`
- **Préfixe options / hooks / tables DB** : `mnti_` → `eimn_` *(breaking change pour les installations existantes)*
- **Commande WP-CLI** : `wp mnti import` → `wp eimn import` *(breaking change)*
- **Remote Git** : `ethersys/Modelo-Netty-Importer` → `ethersys/ethersys-importer-for-modelo-netty`
- **Sécurité** : application de `wp_kses_post()` sur le champ `description` du flux avant insertion en `post_content` (protection XSS contre un flux compromis)
- Correction du nom dans `readme.txt` pour correspondre à l'en-tête PHP

## [1.1.0] - 2026-06-08

Préparation pour le répertoire WordPress Plugin Directory.

### Changements

- Renommage du dossier plugin : `modelo-nettytowpimport/` → `ethersys-importer-for-modelo-netty/`
- Ajout de `readme.txt` (format requis par le répertoire WordPress)
- Correction du `Plugin URI` dans l'en-tête du plugin principal
- Mise à jour du workflow CI (`release.yml`) pour produire le zip avec le nouveau nom
- Mise à jour de toutes les références d'outillage (PHPStan, PHPCS, PHPUnit, wp-env, composer)

## [1.0.0] - 2026-06-08

Version de lancement — première publication open source.

### Prérequis et tests

- WordPress 6.8+, PHP 8.3+
- Matrice de compatibilité validée : WP 6.8.5 / 6.9.4 / 7.0 × PHP 8.3 / 8.4 / 8.5
