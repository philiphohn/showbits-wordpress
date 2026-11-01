<?php
/**
 * Settings → ShowBits.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The connection (where ShowBits is, connect, renew, disconnect), the defaults for imports, and what came in lately.
 * For people who manage the site.
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
		if ( 'connected' === $status['state'] && $stored ) {
			echo '<p>';
			/* translators: %s: the ShowBits workspace's name */
			echo esc_html( sprintf( __( 'Connected to the workspace “%s”.', 'showbits' ), $status['workspace'] ?? $stored['workspace_name'] ) ) . ' ';
			if ( ! empty( $status['expires_at'] ) ) {
				/* translators: %s: a date */
				echo esc_html( sprintf( __( 'The connection lasts until %s; renew it before then.', 'showbits' ), self::date( (string) $status['expires_at'] ) ) );
			}
			echo '</p>';
			$by = get_userdata( $stored['connected_by'] );
			if ( $by ) {
				/* translators: 1: a person, 2: a date, 3: ShowBits' address */
				echo '<p class="description">' . esc_html( sprintf( __( 'Connected by %1$s on %2$s, at %3$s.', 'showbits' ), $by->display_name, self::date( $stored['connected_at'] ), $stored['app_url'] ) ) . '</p>';
			}
			self::connect_form( __( 'Renew the connection', 'showbits' ), $settings['app_url'], false );
			self::disconnect_form();
		} elseif ( 'unreachable' === $status['state'] && $stored ) {
			/* translators: %s: ShowBits' address */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'ShowBits (%s) cannot be reached right now. The connection stays; try again later.', 'showbits' ), $stored['app_url'] ) ) . '</p></div>';
			self::disconnect_form();
		} elseif ( 'ended' === $status['state'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ShowBits no longer accepts this site’s connection: the person who agreed has left the workspace, the year is over, it was disconnected in ShowBits, or this site’s keys changed. Connect it again.', 'showbits' ) . '</p></div>';
			self::connect_form( __( 'Connect again', 'showbits' ), $settings['app_url'], false );
			self::disconnect_form();
		} else {
			echo '<p>' . esc_html__( 'Connect this site to a ShowBits workspace: an admin or owner of the workspace agrees in ShowBits. The site can then take released clips and quote cards into its media library, and receive what your ShowBits admins send.', 'showbits' ) . '</p>';
			self::connect_form( __( 'Connect with ShowBits', 'showbits' ), $settings['app_url'], true );
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
	 * Connecting: ShowBits' address (while not connected) and the button.
	 *
	 * @param string $label   The button.
	 * @param string $app_url ShowBits' address.
	 * @param bool   $editable Whether the address may change (only before the first connection).
	 */
	private static function connect_form( string $label, string $app_url, bool $editable ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="showbits_connect">';
		wp_nonce_field( 'showbits_connect' );
		if ( $editable ) {
			echo '<p><label for="showbits-app-url">' . esc_html__( 'ShowBits address', 'showbits' ) . '</label><br>';
			echo '<input type="url" class="regular-text code" id="showbits-app-url" name="app_url" value="' . esc_attr( $app_url ) . '" required pattern="https://.*"></p>';
			echo '<p class="description">' . esc_html__( 'ShowBits Cloud: https://app.showbits.ai. OnPremise: the address of your installation.', 'showbits' ) . '</p>';
		}
		submit_button( $label, 'primary', 'submit', false );
		echo '</form>';
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
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td>';
			echo '<td>' . esc_html( 'push' === $row['source'] ? __( 'sent from ShowBits', 'showbits' ) : __( 'picked here', 'showbits' ) ) . '</td>';
			echo '<td>' . esc_html( ( $states[ (string) $row['status'] ] ?? (string) $row['status'] ) . ( $row['error'] ? ' (' . $row['error'] . ')' : '' ) ) . '</td></tr>';
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
			'exchange_invalid_grant' => array( 'error', __( 'ShowBits did not take the code (it is only valid for ten minutes, once). Start again.', 'showbits' ) ),
			'exchange_limit'        => array( 'error', __( 'The workspace has as many sites as it may, or the person who agreed has too many tokens.', 'showbits' ) ),
			'exchange_unreachable'  => array( 'error', __( 'ShowBits could not be reached. Check the address and try again.', 'showbits' ) ),
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
