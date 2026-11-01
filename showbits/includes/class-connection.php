<?php
/**
 * Connecting the site to a ShowBits workspace, and ending it.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting (docs/wordpress.md §2), for people who manage this site, in one of two ways that end the same:
 *
 * - paste a site key that an admin or owner made in ShowBits (Integrations → WordPress) for this site's address;
 * - or go to ShowBits' consent page, where an admin or owner agrees and the browser comes back with a site key, valid
 *   ten minutes and only with this attempt's PKCE verifier.
 *
 * Then this site exchanges the key once. It keeps a random pairing value meanwhile; ShowBits asks at the push address
 * for proof of it (Push_Endpoint), so a key copied anywhere else connects nothing, and answers with the site's own key
 * and the push secret, which are stored sealed.
 */
final class Connection {
	private const FLOW    = 'showbits_connect_';
	private const STATUS  = 'showbits_status';
	public const PAIRING  = 'showbits_pairing';
	public const SITE_KEY = '/^sbsk_[A-Za-z0-9_-]{43}$/';

	/** admin-post.php?action=showbits_paste (a form with a nonce): connects with a site key made in ShowBits. */
	public static function paste(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		check_admin_referer( 'showbits_paste' );
		self::take_app_url();
		$key = isset( $_POST['site_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['site_key'] ) ) ) : '';
		if ( ! preg_match( self::SITE_KEY, $key ) ) {
			self::back( 'key_format' );
		}
		self::exchange( Options::settings()['app_url'], $key, null );
	}

	/** admin-post.php?action=showbits_connect (a form with a nonce): off to ShowBits' consent page. */
	public static function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		check_admin_referer( 'showbits_connect' );
		self::take_app_url();
		$app_url  = Options::settings()['app_url'];
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
				'name'      => rawurlencode( self::site_name() ),
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

	/** admin-post.php?action=showbits_callback: ShowBits sends the browser back, with a site key or without one. */
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
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by the state above
			self::back( 'cancelled' );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked by the state above
		if ( ! preg_match( self::SITE_KEY, $code ) ) {
			self::back( 'state' );
		}
		self::exchange( (string) $flow['app_url'], $code, (string) $flow['verifier'] );
	}

	/**
	 * The exchange both ways end in: the pairing value waits (ten minutes at most) while ShowBits asks for proof of it;
	 * then the connection is stored. Connected to another workspace before: that connection ends in ShowBits too (a new
	 * key for the same site renews it in place).
	 *
	 * @param string      $app_url  ShowBits' address.
	 * @param string      $key      The site key.
	 * @param string|null $verifier The PKCE verifier of a key from the consent page.
	 */
	private static function exchange( string $app_url, string $key, ?string $verifier ): void {
		$pairing = Pkce::random();
		set_transient( self::PAIRING, $pairing, 10 * MINUTE_IN_SECONDS );
		$previous = Options::connection();
		$answer   = Api_Client::connect(
			$app_url,
			array(
				'key'      => $key,
				'verifier' => $verifier,
				'site'     => self::site_url(),
				'push'     => rest_url( 'showbits/v1/push' ),
				'name'     => self::site_name(),
				'version'  => SHOWBITS_VERSION,
				'pairing'  => $pairing,
			)
		);
		delete_transient( self::PAIRING );
		if ( is_wp_error( $answer ) ) {
			self::back( 'connect_' . Api_Client::reason( $answer ) );
		}
		$own = (string) ( $answer['siteKey'] ?? '' );
		if ( ! preg_match( '/^sbwp_[A-Za-z0-9_-]{43}$/', $own ) || empty( $answer['pushSecret'] ) || empty( $answer['site']['id'] ) || empty( $answer['workspace']['slug'] ) ) {
			self::back( 'connect_failed' );
		}
		if ( $previous && $previous['site_id'] !== $answer['site']['id'] ) {
			( new Api_Client( $previous['api'], $previous['key'] ) )->leave();
		}
		Options::save_connection(
			array(
				'app_url'        => $app_url,
				'api'            => $app_url . Api_Client::PATH,
				'site_id'        => (string) $answer['site']['id'],
				'workspace_id'   => (string) ( $answer['workspace']['id'] ?? '' ),
				'workspace_slug' => (string) $answer['workspace']['slug'],
				'workspace_name' => (string) ( $answer['workspace']['name'] ?? '' ),
				'key'            => $own,
				'push_secret'    => (string) $answer['pushSecret'],
				'connected_at'   => gmdate( 'c' ),
				'connected_by'   => get_current_user_id(),
			)
		);
		delete_transient( self::STATUS );
		self::back( 'connected' );
	}

	/** admin-post.php?action=showbits_disconnect (a form with a nonce): ends the connection here and in ShowBits. */
	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not connect this site.', 'showbits' ), 403 );
		}
		check_admin_referer( 'showbits_disconnect' );
		$client = Api_Client::connected();
		// Best effort: the site forgets its key either way; ShowBits' admins can still end it on their side.
		if ( $client ) {
			$client->leave();
		}
		Options::forget_connection();
		delete_transient( self::STATUS );
		self::back( 'disconnected' );
	}

	/**
	 * How the connection stands, asked at most every five minutes: connected (with the workspace's current name and
	 * address, and today's imports), ended (ShowBits no longer takes the key: connect again), unreachable, or none.
	 *
	 * @return array{state: string, workspace?: string, pushes_today?: int, pushes_per_day?: int}
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
		$site = ( new Api_Client( $connection['api'], $connection['key'] ) )->site();
		if ( is_wp_error( $site ) ) {
			$reason = Api_Client::reason( $site );
			$status = array( 'state' => in_array( $reason, array( 'unauthorized', 'notFound', 'inactive' ), true ) ? 'ended' : 'unreachable' );
		} else {
			// A renamed workspace has a new address: keep the current one.
			$slug = (string) ( $site['workspace']['slug'] ?? $connection['workspace_slug'] );
			$name = (string) ( $site['workspace']['name'] ?? $connection['workspace_name'] );
			if ( $slug !== $connection['workspace_slug'] || $name !== $connection['workspace_name'] ) {
				$stored                   = get_option( Options::CONNECTION );
				$stored['workspace_slug'] = $slug;
				$stored['workspace_name'] = $name;
				update_option( Options::CONNECTION, $stored, false );
			}
			$status = array(
				'state'          => 'connected',
				'workspace'      => $name,
				'pushes_today'   => (int) ( $site['pushesToday'] ?? 0 ),
				'pushes_per_day' => (int) ( $site['pushesPerDay'] ?? 0 ),
			);
		}
		set_transient( self::STATUS, $status, 5 * MINUTE_IN_SECONDS );
		return $status;
	}

	/** This site's address as ShowBits keeps it (home_url without a trailing slash). */
	public static function site_url(): string {
		return untrailingslashit( home_url() );
	}

	/** This site's title, as plain text. */
	private static function site_name(): string {
		return mb_substr( trim( wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ), 0, 200 );
	}

	/** ShowBits' address from the form: it can change only before the site is connected (afterwards: disconnect first). */
	private static function take_app_url(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- both callers checked the form's nonce
		if ( isset( $_POST['app_url'] ) && ! Options::has_connection() ) {
			$url = Options::app_url( sanitize_text_field( wp_unslash( $_POST['app_url'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- both callers checked the form's nonce
			if ( null === $url ) {
				self::back( 'url' );
			}
			Options::save_settings( array( 'app_url' => $url ) );
		}
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
