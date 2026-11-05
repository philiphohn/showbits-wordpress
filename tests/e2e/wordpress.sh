#!/usr/bin/env bash
# A throwaway WordPress for the checks in tests/e2e (sourced by run.sh and plugin-check.sh): WordPress 7.1 on PHP 8.3
# with MariaDB in Docker, on a network of its own, the plugin mounted read-only. wordpress_up [extra docker args …],
# wordpress_down. Images from Google's mirror of Docker Hub unless E2E_IMAGE_REGISTRY says otherwise, each pinned to the
# digest it was tested with (the same on Docker Hub); a new one: docker buildx imagetools inspect <image:tag>.

REGISTRY="${E2E_IMAGE_REGISTRY:-mirror.gcr.io/library}"
NET=showbits-wp-e2e
HOST=172.30.0.1
WP_PORT=8088
WP_IMAGE="$REGISTRY/wordpress:7.1-php8.3-apache@sha256:848b03bb7b4b160e3a670454e8e928e309c9aaeb1c2584e917f42ab5197662e2"
DB_IMAGE="$REGISTRY/mariadb:11.4@sha256:d1891ede5a559f1086f55053bb8cc1569b0298338c4d72d71725a7f74f6ba620"
export E2E_CLI_IMAGE="$REGISTRY/wordpress:cli-php8.3@sha256:6bcc7bb91f5157c4b915e74ee131873e99c1c1ff48f602e4a6f6cd17827305cb"

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
    "$DB_IMAGE" >/dev/null
  docker run -d --name showbits-wp-e2e-wp --network "$NET" -p "$HOST:$WP_PORT:80" \
    -e WORDPRESS_DB_HOST=showbits-wp-e2e-db -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
    -v "$PWD/showbits:/var/www/html/wp-content/plugins/showbits:ro" \
    "$@" "$WP_IMAGE" >/dev/null
  for _ in $(seq 1 60); do
    if bash tests/e2e/wp.sh db check >/dev/null 2>&1; then break; fi
    sleep 2
  done
  bash tests/e2e/wp.sh core install --url="http://$HOST:$WP_PORT" --title="Blog E2E" --admin_user=admin --admin_password=e2e-password \
    --admin_email=e2e@example.com --skip-email >/dev/null
  # Pretty permalinks (the image writes WordPress' .htaccess); German for the admin (run.sh mounts the plugin's
  # translations as language packs; WordPress' own language pack is not installed, so the site language cannot be German).
  bash tests/e2e/wp.sh rewrite structure '/%postname%/' >/dev/null
  bash tests/e2e/wp.sh user meta update admin locale de_DE >/dev/null
}
