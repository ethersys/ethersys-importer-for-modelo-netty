# Environnement de test local

Environnement Docker WordPress pour tester le plugin [Modelo-NettyToWPImport](https://github.com/ethersys/Modelo-NettyToWPImport) sans licence Houzez.

## Prérequis

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) démarré
- Node.js / npm installé
- `wp-env` installé globalement :
  ```bash
  npm install -g @wordpress/env
  ```

## Premier démarrage

```bash
cp test/.env.example test/.env
# Éditer test/.env et renseigner MNTI_FEED_URL avec l'URL du flux Netty

bash test/setup.sh
```

## Commandes courantes

```bash
# Lancer un import complet
wp-env run cli wp mnti import

# Import sans suppression des biens absents du flux
wp-env run cli wp mnti import --no-delete

# Import sans téléchargement d'images
wp-env run cli wp mnti import --no-images

# Import à blanc (aucune modification en base)
wp-env run cli wp mnti import --dry-run

# Voir les derniers runs en base
wp-env run cli wp db query "SELECT id, status, created_at, counts_json FROM wp_mnti_import_runs ORDER BY id DESC LIMIT 10;"

# Voir les erreurs du dernier run
wp-env run cli wp db query "SELECT level, action, reference_technique, message FROM wp_mnti_import_logs WHERE run_id = (SELECT MAX(id) FROM wp_mnti_import_runs) ORDER BY id;"

# Arrêter l'environnement (conserve les données)
wp-env stop

# Reset complet — repart de zéro
wp-env clean all && bash test/setup.sh
```

## Plugins actifs

| Plugin | Rôle |
|---|---|
| `modelo-nettytowpimport` | Plugin testé |
| `houzez-stub` | Remplace Houzez : post types, taxonomies, aperçu galerie |

## Accès admin

- URL : http://localhost:8888/wp-admin
- Login : `admin`
- Mot de passe : `password`

## Structure des fichiers test

```
test/
├── .wp-env.json       Config wp-env (plugins, PHP version)
├── .env               URL flux Netty — gitignorée, à créer depuis .env.example
├── .env.example       Template commitée
├── setup.sh           Script de démarrage
├── houzez-stub/
│   └── houzez-stub.php   Stub post types + taxonomies Houzez
└── README.md
```

## Tests PHPUnit

### Prérequis

```bash
cd test/phpunit && composer install
```

### Tests unitaires (via wp-env Docker)

```bash
cd test && wp-env run cli bash -c "php /var/www/html/wp-content/mnti-tests/vendor/bin/phpunit -c /var/www/html/wp-content/mnti-tests/phpunit.unit.xml --testdox"
```

### Tests d'intégration (WordPress via wp-env)

wp-env doit être démarré (`bash setup.sh` ou `wp-env start`).

```bash
cd wp-env-test && wp-env run cli bash -c "php /var/www/html/wp-content/mnti-tests/vendor/bin/phpunit -c /var/www/html/wp-content/mnti-tests/phpunit.integration.xml --testdox"
```

> ⚠️ **Les tests d'intégration tournent sur la base wp-env réelle** (`"testsEnvironment": false`). Leur teardown **efface les options du plugin** (dont `mnti_feed_url`, `mnti_schedule_*`). Après une passe de tests d'intégration, relancer `bash setup.sh` pour restaurer les réglages depuis `.env`.

### Lancer un seul test

```bash
# Unit
cd test && wp-env run cli bash -c "php /var/www/html/wp-content/mnti-tests/vendor/bin/phpunit -c /var/www/html/wp-content/mnti-tests/phpunit.unit.xml --filter XmlParserTest --testdox"

# Integration
cd test && wp-env run cli bash -c "php /var/www/html/wp-content/mnti-tests/vendor/bin/phpunit -c /var/www/html/wp-content/mnti-tests/phpunit.integration.xml --filter ImporterIntegrationTest --testdox"
```

### Structure

```
test/phpunit/
├── Unit/                   # Tests sans WordPress (rapides)
│   ├── XmlParserTest.php
│   └── ImporterMappingTest.php
├── Integration/            # Tests avec WordPress réel (via wp-env)
│   ├── ImporterIntegrationTest.php
│   ├── MediaSyncIntegrationTest.php
│   ├── DbIntegrationTest.php
│   ├── AdminIntegrationTest.php
│   └── CronIntegrationTest.php
├── Fixtures/               # XML de flux + fake-image.jpg
├── UnitTestCase.php        # Base class unit (Brain Monkey)
└── Integration/WPTestCase.php  # Base class integration (WP tearDown)
```
