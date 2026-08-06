#!/bin/bash
# Serve the built Drupal site with apache2, WITHOUT ddev. Ports the drupal.org
# GitLab `.drupal-webserver` helper (see .gitlab/ci/drupal.yml) to run inside the
# ghcr.io/drupal-canvas/drupal-testing image, which ships apache2 with docroot
# /var/www/html. Run after the built site has been unpacked.
#
# The functional PHPUnit suite and the browser tests each install their own
# isolated test sites over HTTP, so the served docroot only needs to exist and be
# writable — it does not need a pre-installed base site.
#
# Env:
#   DRUPAL_ROOT   Path to the Drupal project (contains web/). Default: $PWD/_drupal
set -eo pipefail

DRUPAL_ROOT="${DRUPAL_ROOT:-$(pwd)/_drupal}"
WEB="${DRUPAL_ROOT}/web"

echo "::group::Point apache docroot at ${WEB} and start"
rm -rf /var/www/html
ln -s "$WEB" /var/www/html
chown -R www-data:www-data "$DRUPAL_ROOT"

# Test-site installers (run-tests.sh, cypress test-site.php, playwright) write
# here as either root or www-data, so make them world-writable.
for d in sites sites/simpletest sites/default/files sites/default/files/simpletest; do
  mkdir -p "$WEB/$d"
  chmod 777 "$WEB/$d"
done

service apache2 start
echo "::endgroup::"
