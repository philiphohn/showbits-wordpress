<?php
/**
 * PKCE and state for connecting.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Proof Key for Code Exchange (RFC 7636, S256): the verifier stays on this server, ShowBits sees only its hash; a code
 * that leaks on the way back (history, logs, referrer) is worth nothing without it. The state ties the way back to the
 * person who started (no one can slip their own ShowBits workspace into this site).
 */
final class Pkce {
	/** 32 random bytes, base64url without padding: 43 characters. */
	public static function random(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/** The challenge ShowBits keeps: base64url(SHA-256(verifier)). */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
