# Tests

## Matrice de compatibilité testée ✅

Dernier patch de chaque branche, résolu automatiquement (tags GitHub `WordPress/WordPress`) par `test-matrix.sh` et par la CI :

| WordPress | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|-----------|---------|---------|---------|
| 6.9.x     | ✅      | ✅      | ✅      |
| 7.0.x     | ✅      | ✅      | ✅      |
| 7.1.x     | ✅      | ✅      | ✅      |

PHP : dernier patch de chaque branche. `test-matrix.sh` re-télécharge les images Docker `wordpress:phpX.Y` et `wordpress:cli-phpX.Y` avant de lancer la matrice ; la CI part toujours d'images fraîches.

Chaque combo lance trois suites dans le container wp-env :

- `tests/Unit` (11 tests) ;
- `wp-env-test/phpunit/Unit`, Brain Monkey (39 tests) ;
- `wp-env-test/phpunit/Integration`, WordPress réel (56 tests).

## Lancer les tests

### Unit tests localement (rapide)

Require: PHP 8.3+ avec extensions dom, mbstring, xml, xmlwriter

```bash
composer test
```

### Integration tests - une combinaison

Prérequis : `composer install` à la racine et dans `wp-env-test/phpunit`.

```bash
cd wp-env-test
WP_ENV_CORE="WordPress/WordPress#7.1.2" WP_ENV_PHP_VERSION=8.3 wp-env start
wp-env run cli bash -c "php /var/www/html/wp-content/eimn-tests/vendor/bin/phpunit -c /var/www/html/wp-content/eimn-tests/phpunit.integration.xml"
wp-env stop
```

### Integration tests - matrice complète (~10-15 min)

```bash
cd wp-env-test
bash test-matrix.sh
```

## Infrastructure

- **CI**: GitHub Actions (`.github/workflows/test.yml`)
  - Unit tests: matrice PHP 8.3/8.4/8.5
  - Integration tests: matrice WP × PHP (9 combos), versions WP résolues par le job `resolve-wp-versions`

- **Local**: wp-env (`.wp-env.json`) + script bash

- **Dependencies**:
  - PHPUnit 10.5
  - wp-phpunit 7.1
  - yoast/phpunit-polyfills 2.0

## Troubleshooting

### "dom/mbstring extensions not available" (local tests)

Installer: `sudo apt-get install -y php8.3-xml php8.3-mbstring`

### Tests échouent en wp-env

Vérifier:
- Docker running: `docker ps`
- wp-env installed: `npm list -g @wordpress/env` (pas le paquet npm `wp-env`, stub déprécié qui n'exécute rien)
- Disque libre pour image: `df -h`

### Réinitialiser wp-env

```bash
cd wp-env-test
wp-env clean --yes
wp-env start
```
