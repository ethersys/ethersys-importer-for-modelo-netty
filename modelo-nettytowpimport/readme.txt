=== Modelo/Netty to WP Import ===
Contributors: ethersys
Tags: import, real estate, houzez, immobilier, xml
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronise un flux XML Modelo/Netty vers des biens immobiliers WordPress (thème Houzez) : création, mise à jour, suppression, médias, logs et DPE/GES.

== Description ==

Cette extension lit le flux XML généré par le logiciel Modelo (ex Netty), édité par Septeo, et importe les biens en location et vente dans WordPress, sous forme de contenus `property` compatibles avec le thème **Houzez**.

Projet communautaire indépendant : il n'est ni affilié, ni soutenu, ni approuvé par Septeo.

Fonctions principales :

* Import récurrent (WP-Cron) ou manuel (back-office / WP-CLI `wp mnti import`).
* Création, mise à jour et suppression des biens, alignées sur le flux (identité stable via `nh_reference_technique`).
* Synchronisation de la galerie d'images avec garde-fous anti-SSRF, redirections désactivées et limite de taille par image.
* Mapping des champs Netty vers les métadonnées Houzez (`fave_*`) et vers les champs DPE/GES attendus par l'extension `[immowp_dpe_ges]`.
* Journalisation des exécutions (historique et logs détaillés dans l'admin).

**Aucun secret n'est stocké dans le code** : l'URL du flux et la planification se configurent dans le back-office après installation.

Code source et contributions : https://github.com/ethersys/Modelo-NettyToWPImport

== Installation ==

1. Copier le dossier `modelo-nettytowpimport` dans `wp-content/plugins/`.
2. Activer le plugin dans **Extensions**.
3. Régler **Import Netty** dans le menu d'administration (URL du flux, fréquence, agent).

== Frequently Asked Questions ==

= Le thème Houzez est-il obligatoire ? =

Le plugin est conçu pour le modèle de données Houzez (type `property`, métas `fave_*`). Sans Houzez (ou type de contenu / métas compatibles), l'import ne cible pas le bon modèle.

= L'affichage DPE/GES avancé fonctionne-t-il sans extension tierce ? =

Le bloc DPE/GES détaillé nécessite une extension fournissant le shortcode `[immowp_dpe_ges]` (par exemple ImmoWP Diagnostic DPE GES). Sans elle, les champs énergie/GES Houzez restent synchronisés mais le bloc avancé est désactivé.

== Changelog ==

= 1.0.0 =
Version de lancement — première publication open source. Import XML Modelo/Netty vers biens Houzez (création, mise à jour, suppression), synchronisation médias, logs, planification cron, WP-CLI, intégration DPE/GES, garde-fous sécurité images.
