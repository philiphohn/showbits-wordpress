<?php
/**
 * The plugin's own routes in ShowBits.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Calls ShowBits' routes for this plugin (/api/wordpress/v1; errors as {"error": reason}) with the site's own key: who
 * the site is, the library of released clips, imports, a pushed clip's files with the push's ticket, the report on a
 * push, and disconnecting. These routes are not ShowBits' REST API, and the key opens nothing else. A file's link
 * comes back as data; the file itself is fetched without the key.
 */
final class Api_Client {
	private const TIMEOUT = 20;
	/** ShowBits first asks this site at its push address whether it is the plugin that asked (up to ten seconds). */
	private const CONNECT_TIMEOUT = 40;
	public const PATH = '/api/wordpress/v1';

	public function __construct( private string $api, private string $key ) {}

	/** The client for the stored connection; null when there is none. */
	public static function connected(): ?self {
		$connection = Options::connection();
		return $connection ? new self( $connection['api'], $connection['key'] ) : null;
	}

	/**
	 * Exchanges a site key (made in ShowBits, or handed back by its consent page with the PKCE verifier) for the
	 * site's own key and the secret pushes are signed with. Before answering, ShowBits checks at the push address that
	 * this site holds the pairing value (Push_Endpoint).
	 *
	 * @param string               $app_url ShowBits' address.
	 * @param array<string, mixed> $request key, verifier, site, push, name, version, pairing.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function connect( string $app_url, array $request ) {
		$response = wp_remote_post(
			$app_url . self::PATH . '/connect',
			array(
				'timeout'     => self::CONNECT_TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $request ),
			)
		);
		return self::answer( $response );
	}

	/**
	 * The site this key belongs to: its workspace (with its current address) and its plan's pushes a day.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function site() {
		return self::answer( $this->send( 'GET', '/site' ) );
	}

	/**
	 * A page of the library: released clips and quote cards, newest release first.
	 *
	 * @param array{kind?: string, q?: string, cursor?: string, limit?: int} $query Filters and the page.
	 * @return array{data: array<int, array<string, mixed>>, nextCursor: ?string}|\WP_Error
	 */
	public function library( array $query = array() ) {
		$query = array_filter( $query, static fn( $value ) => null !== $value && '' !== $value );
		return self::answer( $this->send( 'GET', '/library' . ( $query ? '?' . http_build_query( $query ) : '' ) ) );
	}

	/**
	 * One released clip or quote card.
	 *
	 * @param string $id The clip's id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function item( string $id ) {
		return self::answer( $this->send( 'GET', '/library/' . rawurlencode( $id ) ) );
	}

	/**
	 * Asks for an import: ShowBits pushes the clip to this site, with the ticket for its files. A quote card's format,
	 * none for a video.
	 *
	 * @param string      $clip_id The clip.
	 * @param string|null $variant 4x5, 9x16 or 1x1 for a quote card.
	 * @return string|\WP_Error The push.
	 */
	public function request_import( string $clip_id, ?string $variant ) {
		$answer = self::answer( $this->send( 'POST', '/imports', array( 'clip' => $clip_id ) + ( null === $variant ? array() : array( 'variant' => $variant ) ) ) );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$push = (string) ( $answer['push'] ?? '' );
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/', $push ) ? $push : new \WP_Error( 'showbits_failed', __( 'ShowBits did not answer as expected.', 'showbits' ), array( 'reason' => 'failed' ) );
	}

	/**
	 * A short-lived link (ten minutes) to one of a pushed clip's files, with the push's ticket. ShowBits logs it as an
	 * export and refuses before the embargo ends (embargo) or for a clip no longer released (notReleased).
	 *
	 * @param string $push_id The push.
	 * @param string $ticket  The ticket the push brought.
	 * @param string $variant video, 16x9, 9x16, 4x5 or 1x1.
	 * @return string|\WP_Error
	 */
	public function file( string $push_id, string $ticket, string $variant ) {
		$answer = self::answer( $this->send( 'POST', '/pushes/' . rawurlencode( $push_id ) . '/files', array( 'ticket' => $ticket, 'variant' => $variant ) ) );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$url = (string) ( $answer['url'] ?? '' );
		return str_starts_with( $url, 'https://' ) || ( apply_filters( 'showbits_allow_private_media', false ) && str_starts_with( $url, 'http://' ) )
			? $url
			: new \WP_Error( 'showbits_failed', __( 'ShowBits did not answer with a file.', 'showbits' ), array( 'reason' => 'failed' ) );
	}

	/**
	 * Tells ShowBits what became of a push.
	 *
	 * @param string               $push_id The push.
	 * @param array<string, mixed> $result  state imported with attachmentUrl, or failed with reason.
	 * @return true|\WP_Error
	 */
	public function report( string $push_id, array $result ) {
		$answer = self::answer( $this->send( 'POST', '/pushes/' . rawurlencode( $push_id ) . '/result', $result ) );
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * Ends the connection in ShowBits (the key too).
	 *
	 * @return true|\WP_Error
	 */
	public function leave() {
		$answer = self::answer( $this->send( 'DELETE', '/site' ) );
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * One request with the site's key.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Below /api/wordpress/v1.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function send( string $method, string $path, ?array $body = null ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->key,
			'Accept'        => 'application/json',
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		return wp_remote_request(
			$this->api . $path,
			array(
				'method'      => $method,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => null === $body ? null : wp_json_encode( $body ),
			)
		);
	}

	/**
	 * A response as data, or as an error with ShowBits' reason ({"error": reason}): `showbits_<reason>`, the status and
	 * the reason in its data.
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_remote_* returned.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function answer( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'showbits_unreachable', __( 'ShowBits could not be reached.', 'showbits' ), array( 'status' => 0, 'reason' => 'unreachable' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = '' === $raw ? array() : json_decode( $raw, true );
		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}
		$reason = is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) && preg_match( '/^[A-Za-z_]{1,40}$/', $data['error'] ) ? $data['error'] : 'failed';
		/* translators: 1: ShowBits' reason, 2: the HTTP status */
		return new \WP_Error( 'showbits_' . $reason, sprintf( __( 'ShowBits refused (%1$s, HTTP %2$d).', 'showbits' ), $reason, $status ), array( 'status' => $status, 'reason' => $reason ) );
	}

	/**
	 * ShowBits' reason in an error from here ('unauthorized', 'embargo' …).
	 *
	 * @param \WP_Error $error The error.
	 */
	public static function reason( \WP_Error $error ): string {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['reason'] ) ? (string) $data['reason'] : 'failed';
	}
}
