<?php
/**
 * Settings → ShowBits.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The connection (where ShowBits is, a site key or ShowBits' consent page, renew, disconnect), the defaults for imports,
 * and what came in lately. For people who manage the site.
 */
final class Settings_Page {
	public const SLUG = 'showbits-settings';

	public static function menu(): void {
		add_options_page( Plugin::label(), Plugin::label(), 'manage_options', self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * A "Settings" link in the plugin list.
	 *
	 * @param array<int|string, string> $links The plugin's links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'showbits' ) . '</a>' );
		return $links;
	}

	/** admin-post.php?action=showbits_settings: the defaults for imports, and ShowBits' address while not connected. */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You may not change these settings.', 'showbits' ), 403 );
		}
		check_admin_referer( 'showbits_settings' );
		$changes = array(
			'card_format'   => isset( $_POST['card_format'] ) && in_array( $_POST['card_format'], Options::CARD_FORMATS, true ) ? sanitize_key( wp_unslash( $_POST['card_format'] ) ) : '4x5',
			'import_poster' => ! empty( $_POST['import_poster'] ),
		);
		$notice  = 'saved';
		if ( ! Options::has_connection() && isset( $_POST['app_url'] ) ) {
			$url = Options::app_url( sanitize_text_field( wp_unslash( $_POST['app_url'] ) ) );
			if ( null === $url ) {
				$notice = 'url';
			} else {
				$changes['app_url'] = $url;
			}
		}
		Options::save_settings( $changes );
		wp_safe_redirect( add_query_arg( 'showbits', $notice, admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}

	public static function render(): void {
		$settings = Options::settings();
		$status   = Connection::status( isset( $_GET['showbits'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- after a change, ask afresh
		$stored   = Options::connection();
		echo '<div class="wrap showbits">';
		echo '<h1>' . esc_html( Plugin::label() ) . '</h1>';
		self::notice();

		echo '<h2>' . esc_html__( 'Connection', 'showbits' ) . '</h2>';
		// wp_salt() keeps salts it makes up in the database, next to the sealed secrets (Crypto).
		if ( ! Crypto::key_in_config() ) {
			/* translators: 1: the constant SHOWBITS_ENCRYPTION_KEY, 2: the file wp-config.php (both stay as they are) */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Your site’s security keys are not in %2$s, so the key that protects the connection to ShowBits is stored in the database. Add %1$s with a long random value to %2$s; if the site is connected already, connect it again afterwards.', 'showbits' ), 'SHOWBITS_ENCRYPTION_KEY', 'wp-config.php' ) ) . '</p></div>';
		}
		if ( 'connected' === $status['state'] && $stored ) {
			echo '<p>';
			/* translators: %s: the ShowBits workspace's name */
			echo esc_html( sprintf( __( 'Connected to the workspace “%s”.', 'showbits' ), Plugin::isolate( (string) ( $status['workspace'] ?? $stored['workspace_name'] ) ) ) ) . ' ';
			if ( ! empty( $status['pushes_per_day'] ) ) {
				/* translators: 1: imports today, 2: imports a day the plan allows */
				echo esc_html( sprintf( __( 'Imports today: %1$d of %2$d.', 'showbits' ), (int) $status['pushes_today'], (int) $status['pushes_per_day'] ) );
			}
			echo '</p>';
			$by = get_userdata( $stored['connected_by'] );
			if ( $by ) {
				/* translators: 1: a person, 2: a date, 3: ShowBits' address */
				echo '<p class="description">' . esc_html( sprintf( __( 'Connected by %1$s on %2$s, at %3$s.', 'showbits' ), Plugin::isolate( $by->display_name ), Plugin::isolate( self::date( $stored['connected_at'] ) ), Plugin::isolate( $stored['app_url'] ) ) ) . '</p>';
			}
			self::disconnect_form();
			echo '<h3>' . esc_html__( 'Connect again', 'showbits' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'With a new site key the site renews its connection, or moves to another workspace.', 'showbits' ) . '</p>';
			self::connect_forms( $settings['app_url'], false );
		} elseif ( 'unreachable' === $status['state'] && $stored ) {
			/* translators: %s: ShowBits' address */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'ShowBits (%s) cannot be reached right now. The connection stays; try again later.', 'showbits' ), Plugin::isolate( $stored['app_url'] ) ) ) . '</p></div>';
			self::disconnect_form();
		} elseif ( 'ended' === $status['state'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ShowBits no longer accepts this site’s connection: it was disconnected in ShowBits, the plan ended, or this site’s keys changed. Connect it again with a new site key.', 'showbits' ) . '</p></div>';
			self::connect_forms( $settings['app_url'], false );
			self::disconnect_form();
		} else {
			echo '<p>' . esc_html__( 'Connect this site to a ShowBits workspace: an admin or owner of the workspace makes a site key for this site in ShowBits (Integrations → WordPress). The site can then take released clips and quote cards into its media library, and receive what your ShowBits admins send.', 'showbits' ) . '</p>';
			self::connect_forms( $settings['app_url'], true );
		}

		echo '<h2>' . esc_html__( 'Imports', 'showbits' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="showbits_settings">';
		wp_nonce_field( 'showbits_settings' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Quote cards', 'showbits' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Format of quote cards', 'showbits' ) . '</legend>';
		$labels = array(
			'4x5'  => __( '4:5 (feeds, articles)', 'showbits' ),
			'1x1'  => __( '1:1 (square)', 'showbits' ),
			'9x16' => __( '9:16 (stories)', 'showbits' ),
		);
		/* translators: %s: a quote card's format, such as 9:16 or "9:16 (stories)" (its animated video with the speaker's voice) */
		$animated               = __( 'Animated %s', 'showbits' );
		$labels['motion-4x5']  = sprintf( $animated, $labels['4x5'] );
		$labels['motion-9x16'] = sprintf( $animated, $labels['9x16'] );
		foreach ( Options::CARD_FORMATS as $format ) {
			echo '<label><input type="radio" name="card_format" value="' . esc_attr( $format ) . '"' . checked( $settings['card_format'], $format, false ) . '> ' . esc_html( $labels[ $format ] ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'The picture pushes bring; in the library you choose for each card.', 'showbits' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Videos', 'showbits' ) . '</th><td><label><input type="checkbox" name="import_poster" value="1"' . checked( $settings['import_poster'], true, false ) . '> ' . esc_html__( 'Import the thumbnail as the video’s poster image', 'showbits' ) . '</label></td></tr>';
		echo '</table>';
		submit_button( __( 'Save', 'showbits' ) );
		echo '</form>';

		self::recent();
		echo '</div>';
	}

	/**
	 * Connecting: ShowBits' address (while not connected), the site key, and the way through ShowBits' consent page.
	 *
	 * @param string $app_url  ShowBits' address.
	 * @param bool   $editable Whether the address may change (only before the first connection).
	 */
	private static function connect_forms( string $app_url, bool $editable ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="showbits-connect">';
		echo '<input type="hidden" name="action" value="showbits_paste">';
		wp_nonce_field( 'showbits_paste' );
		if ( $editable ) {
			self::app_url_field( $app_url );
		}
		echo '<p><label for="showbits-site-key">' . esc_html__( 'Site key', 'showbits' ) . '</label><br>';
		echo '<input type="password" class="regular-text code" id="showbits-site-key" name="site_key" autocomplete="off" spellcheck="false" required pattern="sbsk_[A-Za-z0-9_\-]{43}"></p>';
		/* translators: %s: this site's address */
		echo '<p class="description">' . esc_html( sprintf( __( 'Made in ShowBits for %s, valid for 24 hours and only once. ShowBits then checks that it is talking to this site.', 'showbits' ), Plugin::isolate( Connection::site_url() ) ) ) . '</p>';
		submit_button( __( 'Connect', 'showbits' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="showbits-consent">';
		echo '<input type="hidden" name="action" value="showbits_connect">';
		wp_nonce_field( 'showbits_connect' );
		if ( $editable ) {
			echo '<input type="hidden" name="app_url" value="' . esc_attr( $app_url ) . '">';
		}
		echo '<p class="description">' . esc_html__( 'Are you an admin or owner of the workspace yourself? Then sign in to ShowBits and agree there, without a site key:', 'showbits' ) . '</p>';
		submit_button( __( 'Connect through ShowBits', 'showbits' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * ShowBits' address, while the site is not connected.
	 *
	 * @param string $app_url ShowBits' address.
	 */
	private static function app_url_field( string $app_url ): void {
		echo '<p><label for="showbits-app-url">' . esc_html__( 'ShowBits address', 'showbits' ) . '</label><br>';
		echo '<input type="url" class="regular-text code" id="showbits-app-url" name="app_url" value="' . esc_attr( $app_url ) . '" required pattern="https://.*"></p>';
		echo '<p class="description">' . esc_html__( 'ShowBits Cloud: https://app.showbits.ai. OnPremise: the address of your installation.', 'showbits' ) . '</p>';
	}

	private static function disconnect_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="showbits-disconnect">';
		echo '<input type="hidden" name="action" value="showbits_disconnect">';
		wp_nonce_field( 'showbits_disconnect' );
		submit_button( __( 'Disconnect', 'showbits' ), 'secondary', 'submit', false, array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Disconnect this site from ShowBits? What is in the media library stays.', 'showbits' ) ) . ');' ) );
		echo '</form>';
	}

	/** The latest imports. */
	private static function recent(): void {
		$rows = Imports::recent();
		if ( ! $rows ) {
			return;
		}
		$states = array(
			'requested' => __( 'asked for', 'showbits' ),
			'queued'   => __( 'waiting', 'showbits' ),
			'running'  => __( 'importing', 'showbits' ),
			'imported' => __( 'imported', 'showbits' ),
			'failed'   => __( 'failed', 'showbits' ),
		);
		echo '<h2>' . esc_html__( 'Recent imports', 'showbits' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'showbits' ) . '</th><th>' . esc_html__( 'Clip', 'showbits' ) . '</th><th>' . esc_html__( 'How', 'showbits' ) . '</th><th>' . esc_html__( 'State', 'showbits' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$title = '' !== (string) $row['title'] ? (string) $row['title'] : (string) $row['clip_id'];
			$link  = $row['attachment_id'] ? get_edit_post_link( (int) $row['attachment_id'] ) : null;
			echo '<tr><td>' . esc_html( self::date( (string) $row['created_at'] . ' UTC' ) ) . '</td>';
			// The clip's title in its own direction (bdi), as in the library: it keeps its order in a right-to-left admin.
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '"><bdi>' . esc_html( $title ) . '</bdi></a>' : '<bdi>' . esc_html( $title ) . '</bdi>' ) . '</td>';
			echo '<td>' . esc_html( 'push' === $row['source'] ? __( 'sent from ShowBits', 'showbits' ) : __( 'picked here', 'showbits' ) ) . '</td>';
			echo '<td>' . esc_html( $states[ (string) $row['status'] ] ?? (string) $row['status'] );
			if ( $row['error'] ) {
				echo '<br><span class="description">' . esc_html( Importer::message_of( (string) $row['error'] ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** A notice after connecting, disconnecting or saving (?showbits=…). */
	private static function notice(): void {
		$key      = isset( $_GET['showbits'] ) ? sanitize_key( wp_unslash( $_GET['showbits'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks a message
		$messages = array(
			'connected'             => array( 'success', __( 'Connected. The library is under Media → ShowBits.', 'showbits' ) ),
			'disconnected'          => array( 'success', __( 'Disconnected. What is in the media library stays.', 'showbits' ) ),
			'saved'                 => array( 'success', __( 'Saved.', 'showbits' ) ),
			'cancelled'             => array( 'info', __( 'Connecting was cancelled in ShowBits.', 'showbits' ) ),
			'state'                 => array( 'error', __( 'That way back from ShowBits did not belong to this attempt. Start again.', 'showbits' ) ),
			'url'                   => array( 'error', __( 'The ShowBits address must start with https://.', 'showbits' ) ),
			'key_format'             => array( 'error', __( 'That is not a site key. Copy it from ShowBits (Integrations → WordPress) again.', 'showbits' ) ),
			'connect_key'            => array( 'error', __( 'ShowBits did not take the site key: it is unknown, used, withdrawn or expired (a site key lasts 24 hours, a consent ten minutes). Make a new one.', 'showbits' ) ),
			'connect_otherSite'      => array( 'error', __( 'The site key was made for another address. Make one for this site’s address.', 'showbits' ) ),
			'connect_unreachable'    => array( 'error', __( 'ShowBits could not reach this site at its REST API (/wp-json/showbits/v1/push), or ShowBits itself could not be reached. The site must be public over HTTPS; a firewall or security plugin must let ShowBits in.', 'showbits' ) ),
			'connect_notVerified'    => array( 'error', __( 'This site did not answer ShowBits’ check as expected. Try again; if it fails again, a cache or security plugin may answer for WordPress.', 'showbits' ) ),
			'connect_inactive'       => array( 'error', __( 'The workspace’s plan has ended, or whoever made the site key may no longer connect sites.', 'showbits' ) ),
			'connect_limit'          => array( 'error', __( 'The workspace has as many sites as its plan allows.', 'showbits' ) ),
			'connect_invalid'        => array( 'error', __( 'ShowBits did not accept this site’s address. It must be served over HTTPS.', 'showbits' ) ),
		);
		if ( '' === $key ) {
			return;
		}
		[ $type, $text ] = $messages[ $key ] ?? array( 'error', __( 'Connecting did not work. Try again, or ask your ShowBits contact.', 'showbits' ) );
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** A date in the site's format. */
	private static function date( string $when ): string {
		$time = strtotime( $when );
		return false === $time ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
