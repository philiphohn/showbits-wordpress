#!/usr/bin/env bash
# A throwaway WordPress for the checks in tests/e2e (sourced by run.sh and plugin-check.sh): WordPress 7.1 on PHP 8.3
# with MariaDB in Docker, on a network of its own, the plugin mounted read-only. wordpress_up [extra docker args …],
# wordpress_down. Images from Google's mirror of Docker Hub unless E2E_IMAGE_REGISTRY says otherwise.

REGISTRY="${E2E_IMAGE_REGISTRY:-mirror.gcr.io/library}"
NET=showbits-wp-e2e
HOST=172.30.0.1
WP_PORT=8088
export E2E_CLI_IMAGE="$REGISTRY/wordpress:cli-php8.3"

wordpress_down() {
  docker rm -f showbits-wp-e2e-wp showbits-wp-e2e-db >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}

# Starts WordPress and installs it (admin / e2e-password, German for the admin). Extra arguments go to the
# WordPress container (more mounts, environment).
wordpress_up() {
  wordpress_down
  docker network create --subnet 172.30.0.0/24 --gateway "$HOST" "$NET" >/dev/null
  docker run -d --name showbits-wp-e2e-db --network "$NET" \
    -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
    "$REGISTRY/mariadb:11.4" >/dev/null
  docker run -d --name showbits-wp-e2e-wp --network "$NET" -p "$HOST:$WP_PORT:80" \
    -e WORDPRESS_DB_HOST=showbits-wp-e2e-db -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
    -v "$PWD/showbits:/var/www/html/wp-content/plugins/showbits:ro" \
    "$@" "$REGISTRY/wordpress:7.1-php8.3-apache" >/dev/null
  for _ in $(seq 1 60); do
    if bash tests/e2e/wp.sh db check >/dev/null 2>&1; then break; fi
    sleep 2
  done
  bash tests/e2e/wp.sh core install --url="http://$HOST:$WP_PORT" --title="Blog E2E" --admin_user=admin --admin_password=e2e-password \
    --admin_email=e2e@example.com --skip-email >/dev/null
  # Pretty permalinks (the image writes WordPress' .htaccess); German for the admin (the plugin brings its own
  # translation; WordPress' own language pack is not installed, so the site language cannot be German).
  bash tests/e2e/wp.sh rewrite structure '/%postname%/' >/dev/null
  bash tests/e2e/wp.sh user meta update admin locale de_DE >/dev/null
}
