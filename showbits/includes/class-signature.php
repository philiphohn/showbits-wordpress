<?php
/**
 * The signature on what ShowBits pushes.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * ShowBits signs pushes as it signs webhooks: `ShowBits-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "t.body">`
 * with the secret the site got when it connected. A push counts when one v1 matches and t is at most five minutes
 * away from now (a recorded request cannot be sent again later; the push id is taken once besides).
 */
final class Signature {
	public const TOLERANCE = 300;

	/** Whether the header signs this body with this secret, now. */
	public static function verify( string $header, string $secret, string $body, ?int $now = null, int $tolerance = self::TOLERANCE ): bool {
		if ( '' === $secret || '' === $header || strlen( $header ) > 1000 ) {
			return false;
		}
		$now        = $now ?? time();
		$timestamp  = null;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && preg_match( '/^\d{1,12}$/', $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && preg_match( '/^[0-9a-f]{64}$/', $pair[1] ) ) {
				$signatures[] = $pair[1];
			}
		}
		if ( null === $timestamp || abs( $now - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}

	/** The header for a body (the tests sign with it, as ShowBits does). */
	public static function header( string $secret, string $body, ?int $now = null ): string {
		$timestamp = $now ?? time();
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}
}
