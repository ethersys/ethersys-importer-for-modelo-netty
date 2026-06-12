#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── Config locale ──────────────────────────────────────────────────────────
if [ ! -f .env ]; then
  echo "ERREUR : fichier .env absent."
  echo "  Copier .env.example → .env puis renseigner EIMN_FEED_URL."
  exit 1
fi

# shellcheck source=.env
source .env

if [ -z "${EIMN_FEED_URL:-}" ]; then
  echo "ERREUR : EIMN_FEED_URL non définie dans .env."
  exit 1
fi

# ── Reset BDD ──────────────────────────────────────────────────────────────
echo "→ Réinitialisation de la base de données..."
wp-env reset --yes

# ── Réactivation du plugin (crée les tables custom) ───────────────────────
echo "→ Réactivation du plugin (création des tables)..."
wp-env run cli wp plugin deactivate ethersys-importer-for-modelo-netty
wp-env run cli wp plugin activate ethersys-importer-for-modelo-netty

# ── Plugin Check (PCP) — installé sans activation ─────────────────────────
echo "→ Installation de Plugin Check (PCP)..."
wp-env run cli wp plugin install plugin-check --no-activate

# ── Restauration des options ───────────────────────────────────────────────
echo "→ Restauration des options..."
wp-env run cli wp option update eimn_feed_url "$EIMN_FEED_URL"
wp-env run cli wp option update eimn_schedule_interval 24
wp-env run cli wp option update eimn_schedule_unit hours

# ── Permaliens ────────────────────────────────────────────────────────────
wp-env run cli wp rewrite structure '/%postname%/' --hard

echo ""
echo "✓ Environnement réinitialisé et prêt."
echo ""
