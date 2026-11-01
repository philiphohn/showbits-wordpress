#!/usr/bin/env bash
# The plugin in a real WordPress (tests/e2e/wordpress.sh) against a stand-in ShowBits (tests/e2e/e2e.mjs): activate,
# connect with PKCE, the library, imports with poster and alternative text, a signed push imported by WP-Cron and
# reported, disconnect. Needs Docker, openssl, ffmpeg and Node with Playwright.
#
#   bash tests/e2e/run.sh                 (from wordpress/)
#   E2E_IMAGE_REGISTRY=docker.io/library  images from Docker Hub instead of Google's mirror of it
#
# Screenshots land in tests/e2e/shots/. Everything it starts is removed afterwards.
set -euo pipefail
cd "$(dirname "$0")/../.."
source tests/e2e/wordpress.sh

API_PORT=8443
WORK=$(mktemp -d)
export SHOWBITS_E2E_ORIGIN="https://$HOST:$API_PORT"
# On failure, WordPress' PHP errors first (Apache logs them to the container's output).
trap 'status=$?; if [ "$status" -ne 0 ]; then docker logs --tail 40 showbits-wp-e2e-wp 2>&1 | grep -iE "php|error" | tail -20; fi; wordpress_down; rm -rf "$WORK"' EXIT

# The stand-in's certificate (its address only) and media: a second of video, a picture.
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 1 -subj "/CN=$HOST" -addext "subjectAltName=IP:$HOST" \
  -keyout "$WORK/key.pem" -out "$WORK/cert.pem" 2>/dev/null
ffmpeg -nostdin -v error -f lavfi -i color=c=0x002F2F:s=360x640:d=1 -c:v libx264 -pix_fmt yuv420p -movflags +faststart "$WORK/clip.mp4"
ffmpeg -nostdin -v error -f lavfi -i color=c=0xBFCEDD:s=720x1280:d=1 -frames:v 1 "$WORK/picture.jpg"

wordpress_up -e SHOWBITS_E2E_ORIGIN="$SHOWBITS_E2E_ORIGIN" -v "$PWD/tests/e2e/mu-plugin.php:/var/www/html/wp-content/mu-plugins/showbits-e2e.php:ro"
bash tests/e2e/wp.sh plugin activate showbits >/dev/null

mkdir -p tests/e2e/shots
E2E_HOST="$HOST" E2E_WP_PORT="$WP_PORT" E2E_API_PORT="$API_PORT" E2E_CERT="$WORK/cert.pem" E2E_KEY="$WORK/key.pem" E2E_MEDIA="$WORK" \
  E2E_SHOTS="$PWD/tests/e2e/shots" node tests/e2e/e2e.mjs
