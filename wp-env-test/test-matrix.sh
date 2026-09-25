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
#
# Le dernier tag patch de chaque branche mineure suivie est résolu
# dynamiquement via les tags GitHub (au lieu d'un numéro figé en dur) :
# les patchs sortent régulièrement et la matrice doit rester à jour sans
# édition manuelle. Fallback sur une valeur connue si l'API échoue
# (réseau, jq absent, rate-limit GitHub non-authentifié).
MINOR_BRANCHES=("6.9" "7.0" "7.1")
FALLBACK_PATCH=("6.9.9" "7.0.6" "7.1.2")

WP_VERSIONS=()
WP_LABELS=()

if command -v jq &>/dev/null; then
  GH_TAGS=$(curl -s "https://api.github.com/repos/WordPress/WordPress/tags?per_page=100" || true)
fi

for i in "${!MINOR_BRANCHES[@]}"; do
  BRANCH="${MINOR_BRANCHES[$i]}"
  LATEST=""
  if [ -n "$GH_TAGS" ]; then
    LATEST=$(echo "$GH_TAGS" | jq -r '.[].name' 2>/dev/null \
      | grep -E "^${BRANCH//./\\.}\.[0-9]+$" \
      | sort -V | tail -1)
  fi
  if [ -z "$LATEST" ]; then
    LATEST="${FALLBACK_PATCH[$i]}"
    echo -e "${YELLOW}⚠ Résolution tag $BRANCH.x échouée, fallback sur $LATEST${NC}"
  fi
  WP_VERSIONS+=("WordPress/WordPress#$LATEST")
  WP_LABELS+=("$LATEST")
done

PHP_VERSIONS=("8.3" "8.4" "8.5")

# Suite de tests wp-env-test/phpunit/, montée dans le container (cf. .wp-env.json).
EIMN_TESTS="/var/www/html/wp-content/eimn-tests"

# Ajout dynamique de la dernière bêta/RC en cours (ex: 7.2-RC1), si une
# existe. Interroge la même API que core utilise en interne pour
# channel=beta (cf. plugin wordpress-beta-tester). Best-effort : en cas
# d'erreur réseau/jq absent, on continue sans ce combo plutôt que de
# faire échouer toute la matrice.
if command -v jq &>/dev/null; then
  BETA_JSON=$(curl -s "https://api.wordpress.org/core/version-check/1.7/?channel=beta" || true)
  BETA_URL=$(echo "$BETA_JSON" | jq -r '[.offers[] | select(.response=="development")][0].download // empty' 2>/dev/null)
  BETA_LABEL=$(echo "$BETA_JSON" | jq -r '[.offers[] | select(.response=="development")][0].version // empty' 2>/dev/null)

  if [ -n "$BETA_URL" ] && [ -n "$BETA_LABEL" ]; then
    WP_VERSIONS+=("$BETA_URL")
    WP_LABELS+=("$BETA_LABEL (bêta/RC)")
    echo -e "${YELLOW}→ Bêta/RC détectée : $BETA_LABEL, ajoutée à la matrice${NC}\n"
  fi
else
  echo -e "${YELLOW}⚠ jq absent, pas de détection auto de la dernière bêta/RC${NC}\n"
fi

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

if [ ! -x "$SCRIPT_DIR/phpunit/vendor/bin/phpunit" ] || [ ! -x "$REPO_ROOT/vendor/bin/phpunit" ]; then
  echo -e "${RED}✗ Dépendances absentes. Lancer : composer install (racine et wp-env-test/phpunit)${NC}"
  exit 1
fi

# wp-env construit ses images à partir de wordpress:phpX.Y et
# wordpress:cli-phpX.Y sans jamais les re-télécharger : sans pull explicite,
# un cache local ancien fige le patch PHP testé (ex. 8.5.10 au lieu de 8.5.11).
echo -e "${YELLOW}→ Mise à jour des images PHP (dernier patch de chaque branche)${NC}"
for PHP_VERSION in "${PHP_VERSIONS[@]}"; do
  docker pull -q "wordpress:php$PHP_VERSION" >/dev/null || echo -e "${YELLOW}⚠ Pull wordpress:php$PHP_VERSION échoué, image locale conservée${NC}"
  docker pull -q "wordpress:cli-php$PHP_VERSION" >/dev/null || echo -e "${YELLOW}⚠ Pull wordpress:cli-php$PHP_VERSION échoué, image locale conservée${NC}"
done

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
      # Un core en URL zip (combo bêta/RC dynamique) tronque le basename
      # au premier point (ex "wordpress-7.1-RC3" -> "wordpress-7"),
      # collision possible avec l'image cachée du combo "7.0" précédent.
      # "destroy" force la reconstruction plutôt qu'un "reset" qui
      # réutiliserait l'image potentiellement erronée.
      if [[ "$WP_VERSION" == http* ]]; then
        wp-env destroy --force >/dev/null 2>&1
      else
        wp-env reset --yes >/dev/null 2>&1
      fi
      # Au changement de core/PHP entre deux combos, le premier "start"
      # après "reset" échoue parfois ("Service cli is not running") :
      # on retente une fois.
      if ! wp-env start >/dev/null 2>&1 && ! wp-env start >/dev/null 2>&1; then
        echo "  wp-env start a échoué"
        exit 1
      fi
      sleep 15
      # Le statut du combo = exit code phpunit (l'ancien grep "OK|FAILED"
      # suivi de || true marquait PASSED même avec des tests en échec).
      # Trois suites : unitaires racine (tests/Unit), unitaires Brain Monkey
      # et intégration WordPress réelle (wp-env-test/phpunit/).
      # WP_TESTS_DIR est retiré pour tests/Unit : sinon tests/bootstrap.php
      # charge la lib de tests WP, qui réinstalle WordPress dans la base
      # wp-env partagée ("testsEnvironment": false) et désactive le plugin
      # avant la suite d'intégration.
      STATUS=0
      for SUITE in \
        "cd /var/www/html && env -u WP_TESTS_DIR vendor/bin/phpunit" \
        "php $EIMN_TESTS/vendor/bin/phpunit -c $EIMN_TESTS/phpunit.unit.xml" \
        "php $EIMN_TESTS/vendor/bin/phpunit -c $EIMN_TESTS/phpunit.integration.xml"; do
        if RESULT=$(wp-env run cli bash -c "$SUITE" 2>&1); then
          echo "$RESULT" | grep -E "^(OK|FAILURES|ERRORS|Tests:)" || true
        else
          STATUS=1
          echo "$RESULT" | tail -30
        fi
      done
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
