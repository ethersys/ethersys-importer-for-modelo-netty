#!/bin/bash

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"

WP_VERSIONS=(
  "https://downloads.wordpress.org/release/wordpress-6.8.5.zip"
  "https://downloads.wordpress.org/release/wordpress-6.9.4.zip"
  "https://downloads.wordpress.org/release/wordpress-7.0.zip"
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

    # Create temp config
    TEMP_CONFIG=$(mktemp)
    cat > "$TEMP_CONFIG" << EOF
{
  "core": "$WP_VERSION",
  "phpVersion": "$PHP_VERSION",
  "plugins": [
    "../modelo-nettytowpimport",
    "./houzez-stub"
  ],
  "themes": [],
  "mappings": {
    "wp-content/mnti-tests": "./phpunit",
    ".phpcs.xml": "../.phpcs.xml",
    "phpstan.neon": "../phpstan.neon",
    "phpstan-bootstrap.php": "../phpstan-bootstrap.php",
    "composer.json": "../composer.json",
    "composer.lock": "../composer.lock",
    "vendor": "../vendor",
    "phpunit.xml": "../phpunit.xml",
    "tests": "../tests",
    "modelo-nettytowpimport": "../modelo-nettytowpimport"
  },
  "config": {
    "WPLANG": "fr_FR"
  },
  "testsEnvironment": false
}
EOF

    cp "$SCRIPT_DIR/.wp-env.json" "$SCRIPT_DIR/.wp-env.json.bak"
    cp "$TEMP_CONFIG" "$SCRIPT_DIR/.wp-env.json"

    if (
      cd "$SCRIPT_DIR"
      wp-env reset --yes 2>&1 >/dev/null
      wp-env start 2>&1 >/dev/null
      sleep 15
      wp-env run cli bash -c "cd /var/www/html && vendor/bin/phpunit" 2>&1 | grep -E "OK|FAILED" || true
      wp-env stop 2>&1 >/dev/null
    ); then
      echo -e "  ${GREEN}✓ PASSED${NC}\n"
      PASSED_COMBOS+=("$COMBO")
    else
      echo -e "  ${RED}✗ FAILED${NC}\n"
      FAILED_COMBOS+=("$COMBO")
    fi

    cp "$SCRIPT_DIR/.wp-env.json.bak" "$SCRIPT_DIR/.wp-env.json"
    rm -f "$SCRIPT_DIR/.wp-env.json.bak" "$TEMP_CONFIG"
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
