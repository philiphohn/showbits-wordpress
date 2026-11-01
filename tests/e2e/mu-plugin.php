<?php
/**
 * Only for the end-to-end check (tests/e2e/run.sh), never in a real site: the stand-in ShowBits runs on a private
 * address with a self-signed certificate, so this WordPress may talk to it and download from it.
 */

add_filter(
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( str_starts_with( $url, (string) getenv( 'SHOWBITS_E2E_ORIGIN' ) ) ) {
			$args['sslverify'] = false;
		}
		return $args;
	},
	10,
	2
);
add_filter( 'showbits_allow_private_media', '__return_true' );
