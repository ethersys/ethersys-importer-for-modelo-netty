# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Nature du dépôt

Dépôt GitHub : https://github.com/ethersys/Modelo-NettyToWPImport

Plugin WordPress mono-paquet (PHP 8.3+). Le code source distribuable est intégralement dans `modelo-nettytowpimport/` ; le dossier doit être copié tel quel dans `wp-content/plugins/`. Pas de build, pas de bundler, pas de gestionnaire de dépendances (PHP ou JS) — tout est en PHP natif + un script inline pour le front DPE/GES.

## Commandes utiles

### Exécuter l’import (côté WordPress)

```bash
# Lancement manuel via WP-CLI (depuis la racine d’une install WP)
wp mnti import [--dry-run] [--no-delete] [--no-images]
```

Suite de tests dans `tests/` (PHPUnit + fixtures). Environnement `wp-env-test/` pour tests intégration.

**Versions testées** (matrice complète validée ✅):
- WordPress: 6.8.5, 6.9.4, 7.0
- PHP: 8.3, 8.4, 8.5
- Tous les 9 combos passent

**Tester localement** :
```bash
# Unit tests (PHP 8.3, 8.4, 8.5)
composer test

# Integration tests matrice complète (WP 6.8.5/6.9.4/7.0 × PHP 8.3/8.4/8.5)
# Durée: ~10-15 min pour 9 combos (~1-2 min chacun)
cd wp-env-test && bash test-matrix.sh

# Test une seule combinaison (plus rapide, ~20 min)
cd wp-env-test && wp-env reset --yes && wp-env start && sleep 15 && \
  wp-env run cli bash -c "cd /var/www/html && vendor/bin/phpunit" && wp-env stop
```

Pour validation manuelle : installer le plugin dans un WordPress local + thème Houzez, configurer l’URL de flux dans **Admin → Import Netty**, déclencher l’import et lire les logs (table `{prefix}mnti_import_logs`, écran admin du plugin).

### Packager pour distribution

```bash
# Le .gitignore exclut déjà les zips. Le zip doit contenir le dossier "modelo-nettytowpimport/"
# à la racine pour que WordPress le reconnaisse.
(cd modelo-nettytowpimport && zip -r ../modelo-nettytowpimport.zip .)
```

### Traductions

Fichiers dans `modelo-nettytowpimport/languages/` : `netty-houzez-importer.pot` (template) et `netty-houzez-importer-fr_FR.po`. Le text domain réel utilisé en code est `modelo-nettytowpimport` (constante `Plugin::TEXT_DOMAIN`). Régénérer avec `wp i18n make-pot netty-to-wp-import modelo-nettytowpimport/languages/netty-houzez-importer.pot --domain=modelo-nettytowpimport`.

## Architecture

### Bootstrap

`modelo-nettytowpimport.php` définit les constantes `MNTI_VERSION`, `MNTI_PATH`, `MNTI_URL`, `MNTI_BASENAME` puis délègue à `Plugin::init()` (dans `includes/class-plugin.php`). `Plugin::init()` charge tous les `class-*.php` du dossier `includes/` puis appelle `::init()` sur chaque sous-système. Les hooks d’activation/désactivation y sont aussi enregistrés (création de tables + (re)programmation cron).

Tout le code vit dans le namespace `NettyWP\Import`. Préfixe pour les options, hooks, transients, tables : `mnti_`.

### Flux d’import (cœur métier)

`Importer::run()` orchestre une exécution (manuelle, cron ou CLI). Étapes :

1. `fetch_feed()` → `wp_remote_get()` vers `mnti_feed_url` (jamais en dur dans le code).
2. `XmlParser::parse()` → SimpleXML → tableau de records normalisés (clés à plat, voir `parse_bien()` pour la liste exhaustive des champs Netty supportés).
3. Pour chaque record :
   - Lookup par `meta_key = nh_reference_technique` (constante `Importer::META_REF`). C’est la **clé d’identité stable** entre Netty et WP — toute autre forme d’identification doit passer par là.
   - `upsert_property()` → `wp_insert_post()` + nombreux `update_post_meta()` (mapping vers les clés Houzez `fave_*` et les clés ImmoWP `dpeNumber`/`gesNumber`/…). Les champs Netty sans équivalent Houzez sont stockés en `nh_*`.
   - Taxonomies : `property_status` (toujours mappé à `louer`/`acheter` via `map_status_slug()`), `property_type`, `property_city`, `property_feature` (synchronisation incrémentale — seules les features gérées par le mapping sont touchées, voir `apply_features_from_record()`).
   - `MediaSync::sync_gallery()` → comparaison par `mnti_source_url` (meta sur l’attachment) ; téléchargement en parallèle des URLs nouvelles via `curl_multi_exec` (N slots = constante `MNTI_IMAGE_CONCURRENCY`, défaut 5 ; taille max par image = constante `MNTI_MAX_IMAGE_BYTES`, défaut 20 Mo — les deux surchargeables dans `wp-config.php`) ; validation SSRF (`wp_http_validate_url`) et redirections désactivées avant téléchargement ; sideload séquentiel (`media_handle_sideload`) ; suppression des anciennes images sorties du flux ; première image = thumbnail.
4. Après la boucle : si `delete_missing`, `delete_missing_properties()` supprime les biens WP dont `nh_reference_technique` n’est plus dans le flux (avec leurs médias).
5. `Logger::finish_run_*()` clôt l’entrée dans `mnti_import_runs`.

Toutes les anomalies passent par `Logger::log_error()` (table `mnti_import_logs`) plutôt que par des exceptions remontant à l’utilisateur — sauf en cas d’échec fatal du `try/catch` global qui marque le run en `failed`.

### Cron et déclencheurs

- `Cron` enregistre une recurrence dynamique `mnti_import_recurrence` dont l’intervalle est recalculé depuis `mnti_schedule_interval` × `mnti_schedule_unit` (capé à 30 jours). À chaque enregistrement des réglages, `Admin::handle_save_settings()` appelle `Cron::reschedule_main_import()` qui clear + reschedule. Si l’URL du flux est invalide, **aucun import auto n’est planifié**.
- Une seconde recurrence `mnti_purge_daily` purge les runs anciens (`Db::purge_old_runs()`, garde 200 runs).
- Le verrou d’import est une **option** (`mnti_import_lock`) posée atomiquement via `add_option()` (`Importer::run()`) pour empêcher deux imports simultanés — pas un transient. Elle est libérée dans le `finally` de `run()` et, en cas de run resté bloqué, nettoyée par `cleanup_stale_runs()` après `STALE_RUN_MINS` (30 min).

### Intégration thème (Houzez) et DPE/GES

- `DpeIntegration` n’a d’effet **que si** `shortcode_exists( 'immowp_dpe_ges' )` (plugin tiers présent) **et** sur `is_singular( 'property' )`. Il rend le shortcode dans un `<div id="nti-immowp-dpe-ges">` masqué, puis un script inline le déplace côté client dans `#property-detail-wrap` (ou `#property-energy-class-wrap` en fallback). Toute modification de l’emplacement front doit passer par ce JS.
- `ThemeCompat` et `HouzezSearchI18n` filtrent `gettext` / `houzez_options` pour franciser les libellés. Ce sont des correctifs spécifiques au stack FR — désactivables en retirant l’appel dans `Plugin::init()`.

### Persistance

Deux tables custom créées par `Db::create_tables()` via `dbDelta` à l’activation :

- `{prefix}mnti_import_runs` : un row par exécution (status, dates, source_url, counts_json, error_message).
- `{prefix}mnti_import_logs` : lignes de log liées à un run, indexées par `run_id`, `level`, `action`, `reference_technique`.

Les noms de tables doivent **toujours** être obtenus via `Db::runs_table()` / `Db::logs_table()` (jamais en dur).

## Conventions et garde-fous

- **Aucun secret dans le dépôt** : URL du flux, identifiants, agent — tout passe par les options WP saisies en admin. Un commit qui ajoute une URL Netty en dur est une régression.
- **Identité d’un bien** = `nh_reference_technique`. Ne jamais inventer un autre critère de lookup (titre, slug…). `find_property_id_by_ref()` est le point unique.
- **Mapping Netty → Houzez** : préférer une clé `fave_*` quand Houzez en fournit une ; sinon `nh_*` (ex. `nh_charges`, `nh_type_cuisine`). Les clés `dpeNumber`/`gesNumber`/… sont attendues par l’extension ImmoWP — ne pas les renommer.
- **`property_status`** est verrouillé à deux slugs : `louer` et `acheter`. `map_status_slug()` ramène tout `type_annonce` à l’un ou l’autre ; `map_property_type()` rejette explicitement les valeurs qui appartiendraient au status (`location`/`vente`/…).
- **Features synchronisées** : on ne touche **que** les libellés présents dans `apply_features_from_record()::$feature_map`, pour ne pas écraser des features ajoutées manuellement par l’admin.
- **Images** : la traçabilité repose sur la post-meta `mnti_source_url` côté attachment. Une image sans cette meta sera retéléchargée à la prochaine sync.
- **Logs** : utiliser `Logger::log_info` / `log_error` avec un contexte structuré (clés `reference_technique`, `post_id`, `attachment_id` sont extraites pour les colonnes indexées). Pas de `error_log` direct.

## Points d’extension

- Ajouter un champ Netty → étendre `XmlParser::parse_bien()` (ajouter la clé), puis mapper dans `Importer::upsert_property()` vers une meta `fave_*` (si Houzez) ou `nh_*`.
- Ajouter une option admin → constante d’option (`OPT_*`) dans `Admin`, formulaire + sanitization dans `handle_save_settings()`, lecture dans le sous-système qui en a besoin. Ne pas oublier de relancer `Cron::reschedule_main_import()` si l’option impacte la planification.
- Ajouter une commande WP-CLI → enregistrer dans `Cli::init()` (sous-commande de `wp nti`).
- Purger un cache tiers après import → action `mnti_after_import` (`array $counts, int[] $touched_post_ids`) en fin de `Importer::run()` (succès). Le plugin ne gère en dur que le **cache objet core** de WordPress, et uniquement s’il est persistant (`wp_using_ext_object_cache()`) : il rejoue les `clean_post_cache()` / `clean_taxonomy_cache()` court-circuités par le `wp_suspend_cache_invalidation(true)` de la boucle (ciblé, jamais de `wp_cache_flush()` global). Les caches **page** (WP Rocket, W3TC, LiteSpeed…) doivent se brancher sur ce hook. opcache n’est volontairement pas touché (cache de code PHP, sans rapport avec un import de données).
- Court-circuiter / remplacer le téléchargement d’images → filtre `mnti_pre_download_urls` (`$pre, array $urls, int $timeout`) en tête de `MediaSync::download_urls_parallel()`. Retourner un tableau `map url => chemin tmp | WP_Error` court-circuite `curl_multi` (le reste du pipeline — sideload, traçage `mnti_source_url`, suppression — est inchangé) ; retourner `null` laisse le téléchargement normal s’exécuter. Sert notamment aux tests d’intégration (`MediaSyncIntegrationTest`) pour injecter des fichiers fixtures sans réseau.
