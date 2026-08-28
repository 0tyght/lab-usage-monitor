#!/bin/sh
set -eu

mkdir -p /var/www/html/storage/sessions
chown -R www-data:www-data /var/www/html/storage

php /var/www/html/scripts/init.php
chown -R www-data:www-data /var/www/html/storage

exec "$@"

