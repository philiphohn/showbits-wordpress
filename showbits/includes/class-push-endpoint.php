<?php
/**
 * Where ShowBits pushes released clips.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/showbits/v1/push (docs/wordpress.md §4): open to anyone, so it believes only what ShowBits signed
 * with this site's secret, within five minutes, for this site and workspace. A push is taken once (by its id) and
 * imported by WP-Cron; the answer comes at once (202), the result goes to ShowBits through the API.
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
				// The signature is the check (handle() below); WordPress' session has no part in it.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Answers a push.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public static function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$result = self::handle( $request->get_body(), (string) $request->get_header( 'showbits_signature' ), Options::connection() );
		if ( 202 === $result['status'] && ! empty( $result['queued'] ) ) {
			if ( ! wp_next_scheduled( 'showbits_process_imports' ) ) {
				wp_schedule_single_event( time(), 'showbits_process_imports' );
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
	 * @return array{status: int, reason?: string, queued?: bool}
	 */
	public static function handle( string $body, string $header, ?array $connection ): array {
		// Not (or no longer) connected: ShowBits stops trying.
		if ( ! $connection ) {
			return array(
				'status' => 410,
				'reason' => 'notConnected',
			);
		}
		if ( strlen( $body ) > self::MAX_BODY || ! Signature::verify( $header, (string) $connection['push_secret'], $body ) ) {
			return array(
				'status' => 401,
				'reason' => 'signature',
			);
		}
		$push = json_decode( $body, true );
		$id   = is_array( $push ) ? (string) ( $push['id'] ?? '' ) : '';
		$clip = is_array( $push ) ? (string) ( $push['object']['id'] ?? '' ) : '';
		if ( ! is_array( $push ) || 'clip.push' !== ( $push['type'] ?? '' ) || 'clip' !== ( $push['object']['type'] ?? '' )
			|| ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $clip ) ) {
			return array(
				'status' => 422,
				'reason' => 'invalid',
			);
		}
		// Signed for another site or workspace (a reconnect elsewhere): not ours to take.
		if ( ( $push['site']['id'] ?? '' ) !== $connection['site_id'] || ( $push['workspace']['id'] ?? '' ) !== $connection['workspace_id'] ) {
			return array(
				'status' => 410,
				'reason' => 'otherSite',
			);
		}
		return array(
			'status' => 202,
			'queued' => Imports::enqueue_push( $id, $clip, (int) $connection['connected_by'] ),
		);
	}
}
