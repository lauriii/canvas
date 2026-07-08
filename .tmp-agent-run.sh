#!/bin/bash
# Temporary test runner (delete me).
set -u
cd /var/www/html/web/modules/contrib/canvas
run() {
  SYMFONY_DEPRECATIONS_HELPER=disabled \
  SIMPLETEST_BASE_URL=http://localhost \
  SIMPLETEST_DB=sqlite://localhost/sites/default/files/.ht.sqlite \
  BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest/browser_output \
  php /var/www/html/vendor/bin/phpunit \
    --configuration /var/www/html/web/modules/contrib/canvas/phpunit.xml.dist \
    "$2" --filter "$1" 2>&1 | grep -v browser_output | tail -40
}
wait_mem() {
  while [ "$(free -m | awk 'NR==2 {print $7}')" -lt "${MEM_MIN:-1300}" ]; do
    timeout 15 tail -f /dev/null
  done
}
TARGET="$1"
shift
for ds in "$@"; do
  wait_mem
  echo "=== $ds"
  run "$ds" "$TARGET"
done
