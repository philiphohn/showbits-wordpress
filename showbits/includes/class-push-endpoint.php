<?php
/**
 * Where ShowBits pushes released clips, and asks while connecting.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/showbits/v1/push (docs/wordpress.md §2, §4), open to anyone, so it believes nothing unproven:
 *
 * - `site.verify` while this site connects: ShowBits sends a challenge and gets the HMAC of it under the pairing value
 *   the connect request carried (named by its hash: each attempt has its own). Only this site has that value, and only
 *   for a few seconds; nothing changes here.
 * - `clip.push`: only what ShowBits signed with this site's secret, within five minutes, for this site and workspace,
 *   each push once (by its id; a later delivery brings a new ticket). It comes with a ticket for the clip's files; the
 *   answer comes at once (202), the import in the background, the result goes to ShowBits.
 */
final class Push_Endpoint {
	private const MAX_BODY = 8192;

	public static function register(): void {
		register_rest_route(
			'showbits/v1',
			'/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				// The pairing value or the signature is the check (handle() below); WordPress' session has no part in it.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answers a push or a check.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$pairing = static function ( ?string $id ): ?string {
			$value = get_transient( null === $id ? Connection::LATEST : Connection::PAIRING . $id );
			return is_string( $value ) ? $value : null;
		};
		$result = self::handle( $request->get_body(), (string) $request->get_header( 'showbits_signature' ), Options::connection(), $pairing );
		if ( isset( $result['proof'] ) ) {
			return new \WP_REST_Response( array( 'proof' => $result['proof'] ), 200 );
		}
		if ( 202 === $result['status'] && ! empty( $result['queued'] ) ) {
			if ( ! wp_next_scheduled( Plugin::CRON ) ) {
				wp_schedule_single_event( time(), Plugin::CRON );
			}
			spawn_cron();
		}
		return new \WP_REST_Response( array( 'accepted' => 202 === $result['status'] ) + ( isset( $result['reason'] ) ? array( 'reason' => $result['reason'] ) : array() ), $result['status'] );
	}

	/**
	 * The decision, without WordPress' request objects (the tests call it).
	 *
	 * @param string                    $body       The raw body, as signed.
	 * @param string                    $header     ShowBits-Signature.
	 * @param array<string, mixed>|null $connection The stored connection.
	 * @param callable|null             $pairing    The pairing value of a connect attempt by its id (null: the
	 *                                              latest attempt's), while one runs (string|null).
	 * @return array{status: int, reason?: string, queued?: bool, proof?: string}
	 */
	public static function handle( string $body, string $header, ?array $connection, ?callable $pairing = null ): array {
		if ( strlen( $body ) > self::MAX_BODY ) {
			return array(
				'status' => 413,
				'reason' => 'size',
			);
		}
		$message = json_decode( $body, true );
		if ( is_array( $message ) && 'site.verify' === ( $message['type'] ?? '' ) ) {
			return self::verify( $message, $pairing );
		}
		// Not (or no longer) connected: ShowBits stops trying.
		if ( ! $connection ) {
			return array(
				'status' => 410,
				'reason' => 'notConnected',
			);
		}
		if ( ! Signature::verify( $header, (string) $connection['push_secret'], $body ) ) {
			return array(
				'status' => 401,
				'reason' => 'signature',
			);
		}
		$id      = is_array( $message ) ? (string) ( $message['id'] ?? '' ) : '';
		$clip    = is_array( $message ) ? (string) ( $message['object']['id'] ?? '' ) : '';
		$ticket  = is_array( $message ) ? (string) ( $message['ticket'] ?? '' ) : '';
		$variant = is_array( $message ) ? ( $message['variant'] ?? null ) : null;
		if ( ! is_array( $message ) || 'clip.push' !== ( $message['type'] ?? '' ) || 'clip' !== ( $message['object']['type'] ?? '' )
			|| ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $clip ) || ! preg_match( '/^[0-9a-f]{64}$/', $ticket )
			|| ( null !== $variant && ! in_array( $variant, Importer::CARD_VARIANTS, true ) ) ) {
			return array(
				'status' => 422,
				'reason' => 'invalid',
			);
		}
		// Signed for another site or workspace (a reconnect elsewhere): not ours to take.
		if ( ( $message['site']['id'] ?? '' ) !== $connection['site_id'] || ( $message['workspace']['id'] ?? '' ) !== $connection['workspace_id'] ) {
			return array(
				'status' => 410,
				'reason' => 'otherSite',
			);
		}
		return array(
			'status' => 202,
			'queued' => Imports::enqueue_push( $id, $clip, (int) $connection['connected_by'], $ticket, is_string( $variant ) ? $variant : '' ),
		);
	}

	/**
	 * ShowBits' check while connecting: the HMAC of its challenge under the pairing value it names, or nothing (no such
	 * attempt here). A ShowBits that names none (before pairingId: an older OnPremise) gets the latest attempt's.
	 *
	 * @param array<string, mixed> $message The check.
	 * @param callable|null        $pairing The pairing value of an attempt by its id.
	 * @return array{status: int, reason?: string, proof?: string}
	 */
	private static function verify( array $message, ?callable $pairing ): array {
		$challenge = (string) ( $message['challenge'] ?? '' );
		$id        = isset( $message['pairingId'] ) ? (string) $message['pairingId'] : null;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) || ( null !== $id && ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $id ) ) ) {
			return array(
				'status' => 422,
				'reason' => 'invalid',
			);
		}
		$pairing = null === $pairing ? null : $pairing( $id );
		if ( ! is_string( $pairing ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $pairing ) || ( null !== $id && ! hash_equals( Connection::pairing_id( $pairing ), $id ) ) ) {
			return array(
				'status' => 404,
				'reason' => 'notConnecting',
			);
		}
		return array(
			'status' => 200,
			'proof'  => self::proof( $pairing, $challenge ),
		);
	}

	/** base64url(HMAC-SHA256(pairing, "showbits-site-verify:" . challenge)), as ShowBits expects (lib/wordpress-verify.ts). */
	public static function proof( string $pairing, string $challenge ): string {
		return rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'showbits-site-verify:' . $challenge, $pairing, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC, not obfuscation
	}
}
