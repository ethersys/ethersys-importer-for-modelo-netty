#!/bin/bash

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

# Refs git WordPress/WordPress#X.Y.Z : les URLs zip cassent la résolution
# d'image Docker de wp-env (basename "wordpress-6.8.5" tronqué en "wordpress-6",
# collision de cache entre versions 6.x). Voir commit 2efa024.
WP_VERSIONS=(
  "WordPress/WordPress#6.8.5"
  "WordPress/WordPress#6.9.4"
  "WordPress/WordPress#7.0"
)
WP_LABELS=("6.8.5" "6.9.4" "7.0")
PHP_VERSIONS=("8.3" "8.4" "8.5")

PASSED_COMBOS=()
FAILED_COMBOS=()

# Check Docker
if ! docker ps &>/dev/null; then
  echo -e "${RED}✗ Docker not running. Start Docker first.${NC}"
  exit 1
fi

if ! command -v wp-env &>/dev/null; then
  echo -e "${RED}✗ wp-env not installed. Install with: npm install -g @wordpress/env${NC}"
  exit 1
fi

echo -e "${YELLOW}Testing matrix: WP ${WP_LABELS[@]} × PHP ${PHP_VERSIONS[@]}${NC}\n"

for i in "${!WP_VERSIONS[@]}"; do
  WP_VERSION="${WP_VERSIONS[$i]}"
  WP_LABEL="${WP_LABELS[$i]}"

  for PHP_VERSION in "${PHP_VERSIONS[@]}"; do
    COMBO="WP $WP_LABEL + PHP $PHP_VERSION"
    echo -e "${YELLOW}▶ Testing $COMBO${NC}"

    # Override par variables d'environnement : .wp-env.json n'est jamais
    # modifié (l'ancien mécanisme temp-config + .bak laissait une config
    # cassée en cas d'interruption).
    if (
      cd "$SCRIPT_DIR"
      export WP_ENV_CORE="$WP_VERSION"
      export WP_ENV_PHP_VERSION="$PHP_VERSION"
      wp-env reset --yes >/dev/null 2>&1
      wp-env start >/dev/null 2>&1
      sleep 15
      # Le statut du combo = exit code phpunit (l'ancien grep "OK|FAILED"
      # suivi de || true marquait PASSED même avec des tests en échec).
      RESULT=$(wp-env run cli bash -c "cd /var/www/html && vendor/bin/phpunit" 2>&1)
      STATUS=$?
      echo "$RESULT" | grep -E "^(OK|FAILURES|ERRORS|Tests:)" || true
      wp-env stop >/dev/null 2>&1
      [ "$STATUS" -eq 0 ]
    ); then
      echo -e "  ${GREEN}✓ PASSED${NC}\n"
      PASSED_COMBOS+=("$COMBO")
    else
      echo -e "  ${RED}✗ FAILED${NC}\n"
      FAILED_COMBOS+=("$COMBO")
    fi
  done
done

echo -e "\n${YELLOW}════════════════════════════════════════${NC}"
echo -e "${YELLOW}Summary${NC}"
echo -e "${YELLOW}════════════════════════════════════════${NC}\n"

if [ ${#PASSED_COMBOS[@]} -gt 0 ]; then
  echo -e "${GREEN}✓ Passed (${#PASSED_COMBOS[@]})${NC}"
  for combo in "${PASSED_COMBOS[@]}"; do
    echo "  • $combo"
  done
  echo ""
fi

if [ ${#FAILED_COMBOS[@]} -gt 0 ]; then
  echo -e "${RED}✗ Failed (${#FAILED_COMBOS[@]})${NC}"
  for combo in "${FAILED_COMBOS[@]}"; do
    echo "  • $combo"
  done
  echo ""
  exit 1
fi

echo -e "${GREEN}All combos passed!${NC}\n"
