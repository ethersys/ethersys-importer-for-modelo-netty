# Changelog

## [1.2.10] - 2026-11-26

### Changements

- **Compatibilité WordPress 7.2** : `Tested up to` passé à 7.2 dans `readme.txt`, suite à validation de la matrice de tests (`wp-env-test/test-matrix.sh`) sur WordPress 7.2-RC3, PHP 8.3/8.4/8.5 (ainsi que 6.9.12, 7.0.9 et 7.1.5).

### Corrections

- **Biens réécrits à chaque import sous WordPress 7.2** : depuis WordPress 7.2, `wp_kses()` repose sur la HTML API et enregistre les `<br />` produits par `wpautop()` sous la forme `<br>`. La détection de changement de `Importer::upsert_property()` comparait le contenu calculé au contenu stocké et concluait à tort à une modification : tout bien dont la description contient un saut de ligne était réécrit (`wp_insert_post()`) à chaque exécution. Le titre et le contenu sont désormais comparés tels que WordPress les enregistre (`sanitize_post_field( …, 'db' )`), ce qui couvre aussi les entités (`&`) normalisées par kses.

### Tests

- **Nouveau test** `test_unchanged_description_does_not_rewrite_post` : un second import d'un flux identique ne doit déclencher aucun `wp_insert_post`.
- `test_br_tags_converted_in_post_content` accepte les deux sérialisations (`<br />` et `<br>`).

## [1.2.9] - 2026-09-25

### Changements

- **WordPress minimum 6.9** : `Requires at least` passé de 6.8 à 6.9 (en-tête du plugin, `readme.txt`, `README.md`), la branche 6.8 n'étant plus testée.

### Tests

- **CI** : les tests d'intégration ne s'exécutaient pas. Le workflow installait le paquet npm `wp-env`, un stub déprécié qui affiche seulement « Please run npx @wordpress/env » et sort en succès. Il installe maintenant `@wordpress/env` et lance réellement les suites.
- **Matrice** : `test-matrix.sh` et la CI lancent maintenant les suites `wp-env-test/phpunit/Unit` et `wp-env-test/phpunit/Integration` en plus de `tests/Unit`. Avant, seuls les 11 tests unitaires racine tournaient.
- **Versions testées** : dernier patch des branches WordPress 6.9, 7.0 et 7.1 (6.8 retirée), résolu dynamiquement dans la CI comme dans `test-matrix.sh`.
- **Versions PHP** : `test-matrix.sh` re-télécharge les images Docker de base avant la matrice, pour tester le dernier patch de chaque branche PHP (wp-env ne les met jamais à jour seul).
- **Dépendances** : `wp-phpunit/wp-phpunit` passe en `^7.1` ; `phpcsstandards/php_codesniffer` (abandonné) remplacé par `squizlabs/php_codesniffer`.
- **PHPStan** : chemins de `phpstan.neon` corrigés (ancien dossier `modelo-netty-importer/`).
- **GitHub Actions** : `actions/checkout@v7`, `actions/cache@v6`, `actions/setup-node@v7` et Node 24 (fin de l'avertissement de dépréciation Node.js 20).

## [1.2.8] - 2026-08-13

### Changements

- **Compatibilité WordPress 7.1** : `Tested up to` passé à 7.1 dans `readme.txt`, suite à validation de la matrice de tests (`wp-env-test/test-matrix.sh`) sur WordPress 7.1-RC3, PHP 8.3/8.4/8.5.

## [1.2.7] - 2026-06-17

### Corrections

- **Déploiement SVN WordPress.org** : le workflow `deploy-svn.yml` supprimait tous les anciens tags SVN sauf le courant à chaque déploi, empêchant tout rollback (WordPress.org signalait `/tags/1.2.5/` introuvable). Les anciens tags ne sont plus purgés.
- **`Stable tag` désynchronisé** : `readme.txt` gardait `Stable tag: 1.2.5` alors que la version courante avançait. Le workflow synchronise désormais automatiquement ce champ avec la version du tag déployé.

## [1.2.6] - 2026-06-17

### Corrections

- **Sync features sans thème Houzez** : `apply_features_from_record()` plantait (warnings PHP "Array to string conversion", puis "headers already sent") quand la taxonomie `property_feature` n'existe pas (thème Houzez absent). Ajout d'un garde `taxonomy_exists()` + gestion de l'erreur `wp_get_post_terms()` via `is_wp_error()`, loggée en `feature_sync_failed`.

## [1.2.5] - 2026-06-12

Mise en conformité complète avec les règles de préfixage WordPress.org.

### Changements

- **Post meta `nh_*` → `eimn_*`** : toutes les clés de métadonnées propriétaires renommées (`nh_reference_technique`, `nh_charges`, `nh_type_annonce`, etc.) *(breaking change pour les installations existantes)*
- **Slug menu admin** : `nti-import` → `eimn-import`
- **Handle script DPE** : `nh-dpe-move` → `eimn-dpe-move`
- **HTML/CSS DPE** : `id="nti-immowp-dpe-ges"` et classe `nh-dpe-ges-section` → préfixe `eimn-`
- **Variable d'environnement** : `MNTI_FEED_URL` → `EIMN_FEED_URL` dans `setup.sh`, `reset.sh` et `.env.example`
- **`reset.sh`** : slug plugin corrigé (`modelo-netty-importer` → `ethersys-importer-for-modelo-netty`)
- **Plugin Check (PCP)** : ajouté à `setup.sh` et `reset.sh` (installé sans activation)

## [1.2.1] - 2026-06-10

### Corrections

- **Plugin URI** : corrigé dans l'en-tête PHP (URL GitHub incorrecte après le renommage 1.2.0)
- **`wp-env-test/.wp-env.json`** : core WordPress épinglé à `6.8.5`

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
