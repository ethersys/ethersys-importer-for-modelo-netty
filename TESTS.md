# Tests

## Matrice de compatibilité testée ✅

| WordPress | PHP 8.3 | PHP 8.4 | PHP 8.5 |
|-----------|---------|---------|---------|
| 6.8.5     | ✅      | ✅      | ✅      |
| 6.9.4     | ✅      | ✅      | ✅      |
| 7.0       | ✅      | ✅      | ✅      |

Tous les 9 combos passent (11 tests, 24 assertions chacun).

## Lancer les tests

### Unit tests localement (rapide)

Require: PHP 8.3+ avec extensions dom, mbstring, xml, xmlwriter

```bash
composer test
```

### Integration tests - une combinaison (~20 min)

```bash
cd wp-env-test
wp-env start
wp-env reset --yes
sleep 15
wp-env run cli bash -c "cd /var/www/html && vendor/bin/phpunit"
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
  - Integration tests: matrice WP × PHP (9 combos)

- **Local**: wp-env (`.wp-env.json`) + script bash

- **Dependencies**:
  - PHPUnit 10.5
  - wp-phpunit 6.5
  - yoast/phpunit-polyfills 2.0

## Troubleshooting

### "dom/mbstring extensions not available" (local tests)

Installer: `sudo apt-get install -y php8.3-xml php8.3-mbstring`

### Tests échouent en wp-env

Vérifier:
- Docker running: `docker ps`
- wp-env installed: `npm list -g @wordpress/env`
- Disque libre pour image: `df -h`

### Réinitialiser wp-env

```bash
cd wp-env-test
wp-env clean --yes
wp-env start
```
