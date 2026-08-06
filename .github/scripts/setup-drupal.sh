#!/bin/bash
# Build a Drupal site with the Canvas module installed from the local checkout,
# WITHOUT ddev, inside the ghcr.io/drupal-canvas/drupal-testing image. This is
# the drupal.org GitLab CI approach (see .gitlab/ci/drupal.yml) ported to GitHub
# Actions.
#
# It moves the checkout into a `canvas/` subdirectory, creates a Drupal project
# in `_drupal/`, adds the module as a copied (non-symlink) Composer path
# repository, and requires core-dev plus the module's own require-dev toolchain
# (phpstan/phpcs + the contrib modules the static analysis and kernel/functional
# suites need).
#
# Env:
#   DRUPAL_CORE   Core minor series to install (e.g. 11.3). Default: 11.3.
#
# On success, appends DRUPAL_ROOT and MODULE_DIR to $GITHUB_ENV (when set) and
# prints them, so subsequent steps can `cd "$MODULE_DIR"`.
set -eo pipefail

DRUPAL_CORE="${DRUPAL_CORE:-11.3}"
MODULE_NAME=canvas
# >=X.Y.0,<X.(Y+1).0 — the latest patch of the minor series. Pinning to the
# series (not ^${DRUPAL_CORE}, which resolves to the newest 11.x) keeps
# core-composer-scaffold in the same minor; a floating ^11.4 scaffold breaks the
# scaffold plugin resolve, and the phpstan baselines target the minimum core.
CORE="~${DRUPAL_CORE}.0"

echo "::group::Move checkout into ${MODULE_NAME}/ subdirectory"
# The drupal.org composer template symlinks every project file individually into
# the module directory, which breaks node scripts. Instead, move the whole
# checkout into a subdirectory and use a copying (symlink:false) path repo.
mkdir tmp
find . -maxdepth 1 -type f -not -path "./tmp*" -exec mv {} tmp/ \;
find . -maxdepth 1 -type d -not -path "." -not -path "./tmp" -not -path "./.cache" -exec mv {} tmp/ \;
mv tmp "$MODULE_NAME"
# Rename .git so Composer's path-repo copy carries the git metadata as a plain
# directory; it is restored to .git in the copied module below.
mv "$MODULE_NAME/.git" "$MODULE_NAME/git"
echo "::endgroup::"

echo "::group::composer create-project drupal/recommended-project:${CORE}"
composer create-project "drupal/recommended-project:${CORE}" _drupal --no-install
cd _drupal
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.${MODULE_NAME} --json \
  '{"type": "path", "url": "../'"${MODULE_NAME}"'", "options": { "symlink": false } }'
echo "::endgroup::"

echo "::group::composer require core-dev + ${MODULE_NAME} + module require-dev"
composer require \
  "drupal/${MODULE_NAME} @dev" \
  "drupal/core-recommended:${CORE}" \
  "drupal/core-composer-scaffold:${CORE}" \
  "drupal/core-project-message:${CORE}" \
  "drupal/core-dev:${CORE}" \
  --with-all-dependencies --no-install
# Add the module's own dev dependencies (phpstan/phpcs toolchain + the contrib
# modules the static analysis and kernel/functional suites need).
PACKAGES=$(jq -r '.["require-dev"] // {} | to_entries[] | "\(.key):\(.value)"' \
  "../${MODULE_NAME}/composer.json" | tr '\n' ' ')
composer require ${PACKAGES} --dev -W --no-install
composer install
echo "::endgroup::"

echo "::group::Restore module .git"
mv "web/modules/contrib/${MODULE_NAME}/git" "web/modules/contrib/${MODULE_NAME}/.git"
# Root-owned checkouts in CI trip git's dubious-ownership guard; trust them all.
git config --global --add safe.directory '*'
echo "::endgroup::"

DRUPAL_ROOT="$(pwd)"
MODULE_DIR="${DRUPAL_ROOT}/web/modules/contrib/${MODULE_NAME}"
echo "DRUPAL_ROOT=${DRUPAL_ROOT}"
echo "MODULE_DIR=${MODULE_DIR}"
if [ -n "${GITHUB_ENV:-}" ]; then
  echo "DRUPAL_ROOT=${DRUPAL_ROOT}" >> "$GITHUB_ENV"
  echo "MODULE_DIR=${MODULE_DIR}" >> "$GITHUB_ENV"
fi
