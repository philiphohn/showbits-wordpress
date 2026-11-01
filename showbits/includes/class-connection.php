<?php
/**
 * Connecting the site to a ShowBits workspace, and ending it.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting (docs/wordpress.md §2): a person who manages this site starts here; an admin or owner of the workspace
 * agrees in ShowBits, which sends the browser back with a code; this site exchanges it, with its PKCE verifier, for its
 * token and the secret its pushes are signed with. State and verifier wait ten minutes, for the person who started.
 */
final class Connection {
	private const FLOW = 'showbits_connect_';
	private const STATUS = 'showbits_status';

	/** admin-post.php?action=showbits_connect (a form with a nonce): off to ShowBits' consent page. */
	public static function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		check_admin_referer( 'showbits_connect' );
		// ShowBits' address can change only before the site is connected (afterwards: disconnect first).
		if ( isset( $_POST['app_url'] ) && ! Options::has_connection() ) {
			$url = Options::app_url( sanitize_text_field( wp_unslash( $_POST['app_url'] ) ) );
			if ( null === $url ) {
				self::back( 'url' );
			}
			Options::save_settings( array( 'app_url' => $url ) );
		}
		$settings = Options::settings();
		$app_url  = $settings['app_url'];
		$state    = Pkce::random();
		$verifier = Pkce::random();
		set_transient(
			self::FLOW . get_current_user_id(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'app_url'  => $app_url,
			),
			10 * MINUTE_IN_SECONDS
		);
		$consent = add_query_arg(
			array(
				'site'      => rawurlencode( self::site_url() ),
				'name'      => rawurlencode( wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ),
				'return'    => rawurlencode( admin_url( 'admin-post.php?action=showbits_callback' ) ),
				'state'     => rawurlencode( $state ),
				'challenge' => rawurlencode( Pkce::challenge( $verifier ) ),
			),
			$app_url . '/connect/wordpress'
		);
		self::allow_redirect_to( $app_url );
		wp_safe_redirect( $consent );
		exit;
	}

	/** admin-post.php?action=showbits_callback: ShowBits sends the browser back, with a code or without one. */
	public static function callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		$key   = self::FLOW . get_current_user_id();
		$flow  = get_transient( $key );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the state is the check
		// Once: the state belongs to this person and this one attempt.
		delete_transient( $key );
		if ( ! is_array( $flow ) || '' === $state || ! hash_equals( (string) $flow['state'], $state ) ) {
			self::back( 'state' );
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::back( 'cancelled' );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,200}$/', $code ) ) {
			self::back( 'state' );
		}
		$app_url  = (string) $flow['app_url'];
		$previous = Options::connection();
		$answer   = Api_Client::exchange(
			$app_url,
			array(
				'code'     => $code,
				'verifier' => (string) $flow['verifier'],
				'site'     => self::site_url(),
				'push'     => rest_url( 'showbits/v1/push' ),
				'version'  => SHOWBITS_VERSION,
			)
		);
		if ( is_wp_error( $answer ) ) {
			self::back( 'exchange_' . Api_Client::reason( $answer ) );
		}
		if ( empty( $answer['token'] ) || empty( $answer['pushSecret'] ) || empty( $answer['siteId'] ) || empty( $answer['workspace']['slug'] ) || empty( $answer['api'] ) ) {
			self::back( 'exchange_failed' );
		}
		// Connected to another workspace before: that connection ends in ShowBits too (the same site renews in place).
		if ( $previous && $previous['site_id'] !== $answer['siteId'] ) {
			( new Api_Client( $previous['api'], $previous['token'], $previous['workspace_slug'] ) )->leave();
		}
		Options::save_connection(
			array(
				'app_url'        => $app_url,
				'api'            => untrailingslashit( (string) $answer['api'] ),
				'site_id'        => (string) $answer['siteId'],
				'workspace_id'   => (string) ( $answer['workspace']['id'] ?? '' ),
				'workspace_slug' => (string) $answer['workspace']['slug'],
				'workspace_name' => (string) ( $answer['workspace']['name'] ?? '' ),
				'token'          => (string) $answer['token'],
				'push_secret'    => (string) $answer['pushSecret'],
				'expires_at'     => (string) ( $answer['expiresAt'] ?? '' ),
				'connected_at'   => gmdate( 'c' ),
				'connected_by'   => get_current_user_id(),
			)
		);
		delete_transient( self::STATUS );
		self::back( 'connected' );
	}

	/** admin-post.php?action=showbits_disconnect (a form with a nonce): ends the connection here and in ShowBits. */
	public static function disconnect(): void {
		self::guard( 'showbits_disconnect' );
		$client = Api_Client::connected();
		// Best effort: the site forgets its token either way; ShowBits' admins can still end it on their side.
		if ( $client ) {
			$client->leave();
		}
		Options::forget_connection();
		delete_transient( self::STATUS );
		self::back( 'disconnected' );
	}

	/**
	 * How the connection stands, asked at most every five minutes: connected (with the workspace's current name and
	 * address, and until when), ended (ShowBits no longer takes the token: connect again), unreachable, or none.
	 *
	 * @return array{state: string, workspace?: string, expires_at?: string}
	 */
	public static function status( bool $fresh = false ): array {
		$connection = Options::connection();
		if ( ! $connection ) {
			return array( 'state' => Options::has_connection() ? 'ended' : 'none' );
		}
		$cached = $fresh ? false : get_transient( self::STATUS );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$site = ( new Api_Client( $connection['api'], $connection['token'], $connection['workspace_slug'] ) )->site();
		if ( is_wp_error( $site ) ) {
			$reason = Api_Client::reason( $site );
			$status = array( 'state' => in_array( $reason, array( 'unauthorized', 'notFound' ), true ) ? 'ended' : 'unreachable' );
		} else {
			// A renamed workspace has a new address: keep calling the current one.
			$slug = (string) ( $site['workspace']['slug'] ?? $connection['workspace_slug'] );
			$name = (string) ( $site['workspace']['name'] ?? $connection['workspace_name'] );
			if ( $slug !== $connection['workspace_slug'] || $name !== $connection['workspace_name'] ) {
				$stored                   = get_option( Options::CONNECTION );
				$stored['workspace_slug'] = $slug;
				$stored['workspace_name'] = $name;
				update_option( Options::CONNECTION, $stored, false );
			}
			$status = array(
				'state'      => 'connected',
				'workspace'  => $name,
				'expires_at' => (string) ( $site['expiresAt'] ?? $connection['expires_at'] ),
			);
		}
		set_transient( self::STATUS, $status, 5 * MINUTE_IN_SECONDS );
		return $status;
	}

	/** This site's address as ShowBits keeps it (home_url without a trailing slash). */
	public static function site_url(): string {
		return untrailingslashit( home_url() );
	}

	/** Only people who manage the site, with the form's nonce. */
	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** wp_safe_redirect goes only to hosts WordPress knows: ShowBits' for this redirect. */
	private static function allow_redirect_to( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ) use ( $host ): array {
				$hosts[] = $host;
				return $hosts;
			}
		);
	}

	/** Back to the settings page with a notice. */
	private static function back( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'showbits', $notice, admin_url( 'options-general.php?page=showbits-settings' ) ) );
		exit;
	}
}
