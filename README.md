# Ethersys Importer For Modelo Netty

[![Changelog](https://img.shields.io/badge/changelog-view%20details-lightgrey?style=flat-square&logo=gitbook&logoColor=white)](https://github.com/ethersys/ethersys-importer-for-modelo-netty/blob/master/CHANGELOG.md)
[![Release](https://img.shields.io/github/v/release/ethersys/ethersys-importer-for-modelo-netty?style=flat-square&color=blue)](https://github.com/ethersys/ethersys-importer-for-modelo-netty/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange?style=flat-square)](https://github.com/ethersys/ethersys-importer-for-modelo-netty/blob/master/LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-6.8+-21759b?style=flat-square&logo=wordpress&logoColor=white)
![Last Commit](https://img.shields.io/github/last-commit/ethersys/ethersys-importer-for-modelo-netty/master?style=flat-square)
[![Issues](https://img.shields.io/github/issues/ethersys/ethersys-importer-for-modelo-netty?style=flat-square&color=44cc11)](https://github.com/ethersys/ethersys-importer-for-modelo-netty/issues)

Dépôt : [github.com/ethersys/ethersys-importer-for-modelo-netty](https://github.com/ethersys/ethersys-importer-for-modelo-netty)
Version : 1.2.7

Cette extension permet de lire le flux généré par le logiciel Modelo&copy; (ex Netty&copy;) édité par Septeo&copy; et d'importer les biens en location et vente dans WordPress.

[Modelo&copy;](https://www.septeo.com/fr/offres/modelo-office) est un logiciel professionnel français de gestion d'annonces immobilières.

Cette extension est un projet communautaire indépendant et n’est pas affiliée, soutenue ni approuvée par Septeo&copy;.

## A propos d'ETHERSYS

ETHERSYS est un hébergeur web toulousain spécialisé WordPress qui propose des espaces d'hébergement très performants avec plusieurs services packagés (noms de domaine, emailing) et qui assure un support humain réactif par téléphone avec des administrateurs système. Il propose égaelement des services de création, maintenance et développement de sites WordPress.

## Prérequis : thème Houzez et affichage DPE / GES

- **WordPress** récent (6.8+) et **PHP 8.3+**.

- **Thème Houzez**.

Ce plugin est prévu pour fonctionner avec l’écosystème **[Houzez](https://houzez.co/)** (thème WordPress immobilier) :

- Les biens importés sont des contenus de type **`property`** avec les métadonnées attendues par Houzez (préfixe `fave_*` : prix, surfaces, chambres, images de galerie, classes énergie / GES, agent, etc.).
- Le plugin remplit également des champs personnalisés utilisés sur des sites **francophones** (préfixes `nh_*` pour certains champs Netty sans équivalent direct dans Houzez).

A l'avenir, on peut imaginer un système de connecteurs pour importer les données dans d'autres modèles.

- **Extension fournissant `[immowp_dpe_ges]`** si vous voulez le bloc DPE/GES avancé décrit ci-dessus.

Pour l’**affichage réglementaire DPE / GES** (diagrammes, détails au-delà des simples lettres A–G du thème), le code s’appuie sur [ImmoWP Diagnostic DPE GES](https://fr.wordpress.org/plugins/immowp-diagnostic-dpe-ges/) qui enregistre le shortcode **`[immowp_dpe_ges]`**. Il est possible d'utiliser d'autres extensions fonctionnant sur le même principe.


## Installation rapide

1. Copier le dossier `ethersys-importer-for-modelo-netty` dans `wp-content/plugins/`.
2. Activer le plugin dans **Extensions**.
3. Régler **Import Netty** dans le menu d’administration (URL du flux, fréquence, agent).

## Fonctionnement

Cette extension ne contient **aucune URL de flux ni autre secret** : l’URL Netty et la planification se configurent dans le back-office WordPress après installation du plugin.

1. **Écrit en base** les métadonnées attendues par cette extension (`dpeNumber`, `gesNumber`, `montantEnergieMin` / `Max`, `dateDpe`, etc.) à partir du flux Netty, en complément des champs Houzez (`fave_energy_class`, `fave_ghg_emissions_*`, etc.).
2. **Insère ce shortcode** sur les fiches `property` et utilise un petit script pour **positionner le bloc** dans la mise en page Houzez (section Détails ou zone « énergie »), afin que l’affichage soit cohérent avec le thème.

Sans thème **Houzez** (ou sans type de contenu / métas compatibles), l’import ne cible pas le bon modèle de données. Sans le shortcode **`immowp_dpe_ges`**, la partie intégration front DPE/GES avancée est **désactivée** ; les champs Houzez énergie / GES issus du flux restent toutefois synchronisés.

## Rôle du plugin par rapport à Houzez et au module DPE/GES

| Couche | Rôle |
|--------|------|
| **Netty → flux XML** | Source des annonces, photos, données énergie / GES et financières. |
| **Ethersys Importer For Modelo Netty** | Télécharge le flux, parse le XML, crée / met à jour / supprime les annonces, synchronise les médias, journalise les exécutions, planifie les imports. |
| **Houzez** | Thème et métadonnées `fave_*` pour listes, fiches, agents, recherche. |
| **Extension DPE/GES (`[immowp_dpe_ges]`)** | Rendu détaillé du diagnostic sur la fiche bien, alimenté par les métas écrites à l’import. |

En complément, le plugin applique des **correctifs d’interface français** ciblés sur Houzez (traductions manquantes ou libellés de recherche) via `houzez_options` et le domaine de traduction `houzez` — utiles pour un site entièrement en français ; vous pouvez les désactiver en retirant les classes correspondantes du bootstrap du plugin si votre projet n’en a pas besoin.


## Que fait concrètement le plugin ?

- **Import principal** : lecture équitable du flux, création ou mise à jour des biens, mapping des champs Netty → metas Houzez (+ champs DPE/GES pour l’extension shortcode).
- **Images** : synchronisation de la galerie Houzez (`fave_property_images`) avec traçage de l’URL source. Téléchargement des images manquantes en parallèle (`EIMN_IMAGE_CONCURRENCY`, défaut 5), avec garde-fous : validation anti-SSRF des URLs (refus des IP privées / loopback via `wp_http_validate_url`), redirections HTTP non suivies, et limite de taille par image (`EIMN_MAX_IMAGE_BYTES`, défaut 20 Mo).
- **Suppressions** : option pour retirer de WordPress les biens absents du flux (alignement du catalogue sur Netty).
- **Mise en avant** : correspondance avec la meta Houzez `fave_featured`.
- **Agent / contact** : option pour forcer un agent Houzez (ID) sur toutes les annonces importées.
- **Logs** : historique des exécutions et messages détaillés dans l’admin (**Import Netty**).
- **Planification** : import récurrent via **WP-Cron** (sous réserve de trafic ou cron système).
- **WP-CLI** : commande `wp eimn import` pour lancer l’import hors navigateur.

## Options disponibles (back-office et base de données)

Ces réglages sont disponibles dans **WordPress Admin → Import Netty** (formulaire « Réglages »). Côté code, ce sont des options WordPress :

| Clé option (`get_option`) | Type | Description |
|---------------------------|------|-------------|
| `eimn_feed_url` | string (URL) | URL du flux XML Netty (`http` ou `https`). Obligatoire pour importer. |
| `eimn_schedule_interval` | int (1–999) | Multiplicateur de la fréquence d’import automatique. |
| `eimn_schedule_unit` | string | Unité : `minute`, `hour` ou `day` (avec `eimn_schedule_interval`). |
| `eimn_default_agent_id` | int | ID de l’agent Houzez à utiliser pour le contact ; `0` = comportement par défaut (auteur du bien). |

À l’enregistrement des réglages, le plugin **replanifie** la tâche WP-Cron d’import. Si l’URL du flux est vide ou invalide, **aucun import automatique** n’est planifié.

### Constantes PHP (wp-config.php)

| Constante | Défaut | Description |
|-----------|--------|-------------|
| `EIMN_IMAGE_CONCURRENCY` | `5` | Nombre maximum de téléchargements d’images simultanés par run. Augmenter sur un serveur avec bonne bande passante, réduire en cas de contrainte réseau.<br>`define( ‘EIMN_IMAGE_CONCURRENCY’, 10 );` |
| `EIMN_MAX_IMAGE_BYTES` | `20971520` (20 Mo) | Taille maximale d’une image téléchargée. Au-delà, l’image est ignorée et journalisée (`file_too_large`) — garde-fou contre l’épuisement disque. Valeur en octets.<br>`define( ‘EIMN_MAX_IMAGE_BYTES’, 10 * 1024 * 1024 );` |

Autres éléments exposés dans la même page d’administration :

- **Lancer l’import maintenant** : exécute un import complet **de façon synchrone** dans la requête (images + suppression des manquants, comme le cron) et affiche le résultat réel au retour. Sur un très gros catalogue susceptible de dépasser le délai d’exécution du serveur web, préférer WP-CLI (`wp eimn import`) ou un cron système.
- **Tester la connexion au flux** : enregistre les réglages puis vérifie l’URL saisie (connexion + parsing), sans modifier les biens.
- **Historique des exécutions** : tableau des **20 *runs* les plus récents** ; lien vers le détail et les **200 derniers logs** par run.

## WP-CLI

Le support de WP-CLI est prévu pour lancer les imports manuellement.

```bash
wp eimn import [--dry-run] [--no-delete] [--no-images]
```

## WP-Cron

Les imports automatiques dépendent de **WP-Cron**. 

Nous recommandons de prévoir un **cron système** afin que les tâches d'import soient effectivement lancées aux horaires prévus.

### Développement

- **Namespace** : `Ethersys\NettyImport`
- **Préfixe hooks / transients** : `eimn_`
- **Tables** : `{prefix}eimn_import_runs`, `{prefix}eimn_import_logs`
- **Cache** : en fin d'import, le plugin rejoue les invalidations du **cache objet** core (uniquement s'il est persistant) sur les biens et taxonomies touchés — ciblé, jamais de purge globale. Pour les caches **page** (WP Rocket, W3TC, LiteSpeed…), branchez votre purge sur l'action `eimn_after_import` (`array $counts, int[] $touched_post_ids`).

### Licence

GPLv2 ou ultérieure (comme WordPress). Voir le fichier [`LICENSE`](LICENSE) à la racine du dépôt et l’en-tête du fichier principal du plugin.

## Contribuer

Les contributions sont les bienvenues sur [GitHub](https://github.com/ethersys/ethersys-importer-for-modelo-netty). Voir [`CONTRIBUTING.md`](CONTRIBUTING.md) pour le workflow, les conventions de code et la procédure de test.