#!/bin/sh
set -eu

mkdir -p /var/www/html/storage/sessions
chown -R www-data:www-data /var/www/html/storage

php /var/www/html/scripts/init.php
chown -R www-data:www-data /var/www/html/storage

# Run the timeout worker with the same permissions as Apache. It retries on
# the next interval if the database is temporarily busy or unavailable.
(
    while :; do
        su -s /bin/sh www-data -c 'php /var/www/html/scripts/close-expired.php' || true
        sleep 30
    done
) &
worker_pid=$!
"$@" &
server_pid=$!
stop_children() {
    kill "$worker_pid" "$server_pid" 2>/dev/null || true
}
trap stop_children TERM INT
wait "$server_pid" || status=$?
stop_children
exit "${status:-0}"

