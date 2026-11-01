<?php
/**
 * Encryption of the connection's secrets at rest.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The token and the push secret live in the options table, which backups, exports and SQL injections reach: they are
 * stored encrypted (libsodium secretbox, XSalsa20-Poly1305) with a key that lives in wp-config.php, either its own
 * (SHOWBITS_ENCRYPTION_KEY) or one derived from the site's salts. New salts make the stored secrets unreadable: then
 * the site connects again.
 */
final class Crypto {
	private const PREFIX = 'sb1:';

	/** The key: from SHOWBITS_ENCRYPTION_KEY if defined, else from AUTH_KEY and SECURE_AUTH_KEY with their salts. */
	private static function key(): string {
		$material = defined( 'SHOWBITS_ENCRYPTION_KEY' ) && is_string( SHOWBITS_ENCRYPTION_KEY ) && '' !== SHOWBITS_ENCRYPTION_KEY
			? SHOWBITS_ENCRYPTION_KEY
			: wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'showbits-credentials' );
	}

	/** Seals a secret: a version mark, then nonce and ciphertext in base64. */
	public static function seal( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** Opens a sealed secret; null when it is not one or the key changed. */
	public static function open( string $sealed ): ?string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}
}
