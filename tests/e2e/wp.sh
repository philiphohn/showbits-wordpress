#!/usr/bin/env bash
# WP-CLI against the end-to-end WordPress (tests/e2e/run.sh): its own container, the site's files and database.
exec docker run --rm --user 33:33 --network showbits-wp-e2e --volumes-from showbits-wp-e2e-wp \
  -e WORDPRESS_DB_HOST=showbits-wp-e2e-db -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress -e WORDPRESS_DB_NAME=wordpress \
  -e SHOWBITS_E2E_ORIGIN="${SHOWBITS_E2E_ORIGIN:-}" -e HOME=/tmp \
  "${E2E_CLI_IMAGE:-mirror.gcr.io/library/wordpress:cli-php8.3}" wp "$@"
