#!/bin/bash
cd /var/www/html/web/modules/contrib/canvas
mkdir -p .cache/phpcs
exec ../../../../vendor/bin/phpcs $(tr '\n' ' ' < .pr7-phpcs-files.txt)
