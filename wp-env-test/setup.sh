#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── Prérequis ──────────────────────────────────────────────────────────────
if ! command -v wp-env &>/dev/null; then
  echo "ERREUR : wp-env introuvable. Installer avec : npm install -g @wordpress/env"
  exit 1
fi

if ! command -v docker &>/dev/null; then
  echo "ERREUR : Docker introuvable. Installer Docker Desktop."
  exit 1
fi

# ── Config locale ──────────────────────────────────────────────────────────
if [ ! -f .env ]; then
  echo "ERREUR : fichier .env absent."
  echo "  Copier .env.example → .env puis renseigner MNTI_FEED_URL."
  exit 1
fi

# shellcheck source=.env
source .env

if [ -z "${MNTI_FEED_URL:-}" ]; then
  echo "ERREUR : MNTI_FEED_URL non définie dans .env."
  exit 1
fi

# ── Démarrage ──────────────────────────────────────────────────────────────
echo "→ Démarrage de wp-env..."
wp-env start

# ── Mise à jour BDD (évite l'écran "Database update required") ────────────
wp-env run cli wp core update-db

# ── Thème ─────────────────────────────────────────────────────────────────
echo "→ Activation de Twenty Twenty-Five, suppression des anciens thèmes..."
wp-env run cli wp theme activate twentytwentyfive
wp-env run cli bash -c "wp theme delete \$(wp theme list --field=name --status=inactive --format=csv 2>/dev/null) 2>/dev/null" || true

# ── Langue & traductions ───────────────────────────────────────────────────
echo "→ Installation de la langue fr_FR et mise à jour des traductions..."
wp-env run cli wp language core install fr_FR --activate
wp-env run cli wp user meta update 1 locale fr_FR
wp-env run cli wp language core update
wp-env run cli wp language plugin update --all
wp-env run cli wp language theme update --all

# ── Configuration plugin ───────────────────────────────────────────────────
echo "→ Configuration de Modelo-NettyToWPImport..."
wp-env run cli wp option update mnti_feed_url "$MNTI_FEED_URL"
wp-env run cli wp option update mnti_schedule_interval 24
wp-env run cli wp option update mnti_schedule_unit hours

# ── Permaliens (requis pour les CPT) ───────────────────────────────────────
wp-env run cli wp rewrite structure '/%postname%/' --hard

echo ""
echo "✓ Environnement prêt."
echo ""
echo "  Front  : http://localhost:8888"
echo "  Admin  : http://localhost:8888/wp-admin"
echo "  Login  : admin / password"
echo ""
echo "  Lancer un import :"
echo "    wp-env run cli wp mnti import"
echo "    wp-env run cli wp mnti import --dry-run"
echo ""
echo "  Vider les données et remettre à zéro proprement :"
echo "    bash reset.sh"
echo ""
echo "  Arrêter l'environnement (données conservées) :"
echo "    wp-env stop"
echo ""
