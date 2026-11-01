<?php
/**
 * The ShowBits REST API, as far as the plugin uses it.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Calls ShowBits' REST API (/api/v1, problem details on errors) with the connection's token: the library of released
 * clips, a file's short-lived link, the site's own record, the report on a push, and disconnecting. A file's link is
 * read without following it, so the token never travels to the media store; the file itself is fetched without it.
 */
final class Api_Client {
	private const TIMEOUT = 20;

	public function __construct( private string $api, private string $token, private string $workspace ) {}

	/** The client for the stored connection; null when there is none. */
	public static function connected(): ?self {
		$connection = Options::connection();
		return $connection ? new self( $connection['api'], $connection['token'], $connection['workspace_slug'] ) : null;
	}

	/**
	 * Exchanges the code from the consent page for the connection (no token yet: its own route outside /v1).
	 *
	 * @param string               $app_url ShowBits' address.
	 * @param array<string, mixed> $request Code, verifier, site, push address, version.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function exchange( string $app_url, array $request ) {
		$response = wp_remote_post(
			$app_url . '/api/wordpress/token',
			array(
				'timeout'     => self::TIMEOUT,
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
	 * A page of the library: released clips and quote cards, newest release first.
	 *
	 * @param array{kind?: string, q?: string, cursor?: string, limit?: int} $query Filters and the page.
	 * @return array{data: array<int, array<string, mixed>>, nextCursor: ?string}|\WP_Error
	 */
	public function library( array $query = array() ) {
		$query = array_filter( $query, static fn( $value ) => null !== $value && '' !== $value );
		return $this->get( '/workspaces/' . rawurlencode( $this->workspace ) . '/library' . ( $query ? '?' . http_build_query( $query ) : '' ) );
	}

	/**
	 * One released clip or quote card.
	 *
	 * @param string $id The clip's id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function item( string $id ) {
		return $this->get( '/workspaces/' . rawurlencode( $this->workspace ) . '/library/' . rawurlencode( $id ) );
	}

	/**
	 * The short-lived download link to a file (an hour). ShowBits logs it as an export and refuses before the embargo
	 * ends (409 embargo) or for a clip no longer released (409 notReleased).
	 *
	 * @param string $id      The clip's id.
	 * @param string $variant video, 16x9, 9x16, 4x5 or 1x1.
	 * @return string|\WP_Error
	 */
	public function file_location( string $id, string $variant ) {
		$response = $this->send( 'GET', '/workspaces/' . rawurlencode( $this->workspace ) . '/library/' . rawurlencode( $id ) . '/file?variant=' . rawurlencode( $variant ), null, array( 'redirection' => 0 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( 302 === $status && str_starts_with( $location, 'https://' ) ) {
			return $location;
		}
		$answer = self::answer( $response );
		return is_wp_error( $answer ) ? $answer : new \WP_Error( 'showbits_failed', __( 'ShowBits did not answer with a file.', 'showbits' ), array( 'status' => $status ) );
	}

	/**
	 * The site this token belongs to: its workspace (with its current address) and until when the token lives.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function site() {
		return $this->get( '/wordpress/site' );
	}

	/**
	 * Tells ShowBits what became of a push.
	 *
	 * @param string               $push_id The push.
	 * @param array<string, mixed> $result  state imported with attachmentUrl, or failed with reason.
	 * @return true|\WP_Error
	 */
	public function report( string $push_id, array $result ) {
		$answer = self::answer( $this->send( 'POST', '/wordpress/pushes/' . rawurlencode( $push_id ) . '/result', $result ) );
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * Ends the connection in ShowBits (the token too).
	 *
	 * @return true|\WP_Error
	 */
	public function leave() {
		$answer = self::answer( $this->send( 'DELETE', '/wordpress/site' ) );
		return is_wp_error( $answer ) ? $answer : true;
	}

	/**
	 * A GET, decoded.
	 *
	 * @param string $path Below /v1.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get( string $path ) {
		return self::answer( $this->send( 'GET', $path ) );
	}

	/**
	 * One request with the token.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path   Below /v1.
	 * @param array<string, mixed>|null $body   JSON body.
	 * @param array<string, mixed>      $args   More arguments for wp_remote_request.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function send( string $method, string $path, ?array $body = null, array $args = array() ) {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->token,
			'Accept'        => 'application/json',
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		return wp_remote_request(
			$this->api . '/v1' . $path,
			array_merge(
				array(
					'method'  => $method,
					'timeout' => self::TIMEOUT,
					'headers' => $headers,
					'body'    => null === $body ? null : wp_json_encode( $body ),
				),
				$args
			)
		);
	}

	/**
	 * A response as data, or as an error with ShowBits' reason (problem details): `showbits_<reason>`, the status and
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
		$reason = is_array( $data ) && isset( $data['reason'] ) && is_string( $data['reason'] ) && preg_match( '/^[A-Za-z_]{1,40}$/', $data['reason'] ) ? $data['reason'] : 'failed';
		$detail = is_array( $data ) && isset( $data['detail'] ) && is_string( $data['detail'] ) ? $data['detail'] : sprintf( 'HTTP %d', $status );
		return new \WP_Error( 'showbits_' . $reason, $detail, array( 'status' => $status, 'reason' => $reason ) );
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
