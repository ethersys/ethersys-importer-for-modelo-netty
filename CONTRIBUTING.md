# Contribuer

Merci de votre intérêt pour **Modelo Netty Importer**. Les contributions (issues, correctifs, documentation) sont les bienvenues.

## Signaler un bug ou proposer une évolution

Ouvrez une [issue](https://github.com/ethersys/Modelo-Netty-Importer/issues) en décrivant :

- la version du plugin, de WordPress et de PHP ;
- le thème utilisé (Houzez et sa version) ;
- les étapes de reproduction et le comportement attendu vs observé ;
- les logs pertinents (écran **Import Netty → Historique**, ou table `{prefix}mnti_import_logs`).

**Ne joignez jamais de secret** (URL de flux réelle, identifiants, données clients) à une issue ou une PR.

## Prérequis de développement

- PHP **8.3+**
- Composer (dépendances de développement uniquement : PHPUnit, PHPStan, PHP_CodeSniffer)
- Un WordPress local avec le thème Houzez pour les tests d'intégration (voir `wp-env-test/`)

```bash
composer install
```

## Conventions de code

- Le code distribuable vit intégralement dans `modelo-netty-importer/`, namespace `Modelo\NettyImport`, préfixe `mnti_` pour options, hooks, transients et tables.
- Respecter les **WordPress Coding Standards** (configuration `.phpcs.xml`).
- Pas de secret en dur, pas de `error_log` direct (utiliser `Logger`), noms de tables via `Db::runs_table()` / `Db::logs_table()`.
- Voir `CLAUDE.md` pour l'architecture détaillée et les garde-fous.

## Vérifications avant une PR

```bash
composer lint      # PHP_CodeSniffer (WPCS)
composer analyse   # PHPStan
composer test      # PHPUnit (tests unitaires)
```

Les tests d'intégration (`wp-env-test/`) nécessitent un environnement WordPress ; voir `wp-env-test/README.md`.

## Pull requests

1. Forkez le [dépôt](https://github.com/ethersys/Modelo-Netty-Importer) et créez une branche dédiée.
2. Gardez la PR ciblée (un sujet par PR) et mettez à jour `CHANGELOG.md`.
3. Décrivez le changement et la façon de le tester.

## Licence

En contribuant, vous acceptez que votre contribution soit distribuée sous licence **GPL-2.0-or-later**, comme le reste du projet (voir le fichier [`LICENSE`](LICENSE)).
