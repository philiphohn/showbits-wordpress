<?php
/**
 * Media → ShowBits.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The library: released clips and quote cards of the connected workspace, newest release first, searchable; pick some
 * and import them into the media library (assets/library.js). For everyone who may upload media.
 */
final class Library_Page {
	public const SLUG = 'showbits';

	public static function menu(): void {
		$hook = add_media_page( Plugin::label(), Plugin::label(), Plugin::capability(), self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action(
				'admin_enqueue_scripts',
				static function ( string $current ) use ( $hook ): void {
					if ( $current === $hook ) {
						self::assets();
					}
				}
			);
		}
	}

	private static function assets(): void {
		wp_enqueue_style( 'showbits-admin', plugins_url( 'assets/admin.css', SHOWBITS_FILE ), array(), SHOWBITS_VERSION );
		wp_enqueue_script( 'showbits-library', plugins_url( 'assets/library.js', SHOWBITS_FILE ), array( 'wp-api-fetch' ), SHOWBITS_VERSION, true );
		$settings = Options::settings();
		$data     = array(
			'cardFormat' => $settings['card_format'],
			'locale'     => str_replace( '_', '-', get_user_locale() ),
			'strings'    => array(
				'all'         => __( 'All', 'showbits' ),
				'clips'       => __( 'Clips', 'showbits' ),
				'quotes'      => __( 'Quote cards', 'showbits' ),
				'search'      => __( 'Search titles', 'showbits' ),
				'searchLabel' => __( 'Search the library', 'showbits' ),
				'import'      => __( 'Import selected', 'showbits' ),
				/* translators: 1: how many are done, 2: how many in all */
				'importing'   => __( 'Importing %1$d of %2$d …', 'showbits' ),
				/* translators: %d: how many clips were imported */
				'done'        => __( 'Imported: %d', 'showbits' ),
				'loadMore'    => __( 'Load more', 'showbits' ),
				'loading'     => __( 'Loading …', 'showbits' ),
				'empty'       => __( 'No released clips or quote cards yet.', 'showbits' ),
				/* translators: %s: date and time the embargo ends */
				'embargo'     => __( 'Embargo until %s', 'showbits' ),
				'noFile'      => __( 'Not ready yet', 'showbits' ),
				'inLibrary'   => __( 'In the media library', 'showbits' ),
				'outdated'    => __( 'An older version is in the media library', 'showbits' ),
				'edit'        => __( 'Edit', 'showbits' ),
				'format'      => __( 'Format', 'showbits' ),
				'video'       => __( 'Video', 'showbits' ),
				'quote'       => __( 'Quote card', 'showbits' ),
				'meme'        => __( 'Meme', 'showbits' ),
				/* translators: %s: why it failed */
				'failed'      => __( 'Failed: %s', 'showbits' ),
				/* translators: %s: why it failed */
				'loadFailed'  => __( 'The library could not be loaded: %s', 'showbits' ),
			),
		);
		wp_add_inline_script( 'showbits-library', 'window.showbitsLibrary = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	public static function render(): void {
		$connected = Options::connection();
		echo '<div class="wrap showbits">';
		echo '<h1>' . esc_html( Plugin::label() ) . '</h1>';
		if ( ! $connected ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This site is not connected to ShowBits yet.', 'showbits' ) . ' ';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<a href="' . esc_url( admin_url( 'options-general.php?page=' . Settings_Page::SLUG ) ) . '">' . esc_html__( 'Connect it in the settings.', 'showbits' ) . '</a>';
			} else {
				echo esc_html__( 'An administrator of this site connects it in the settings.', 'showbits' );
			}
			echo '</p></div></div>';
			return;
		}
		echo '<p class="showbits-intro">';
		/* translators: %s: the ShowBits workspace's name */
		echo esc_html( sprintf( __( 'Released clips and quote cards from the ShowBits workspace “%s”. What you import lands in the media library, ready for posts and pages.', 'showbits' ), $connected['workspace_name'] ) );
		echo '</p>';
		echo '<div id="showbits-library" class="showbits-library" aria-live="polite"></div>';
		echo '<noscript><p>' . esc_html__( 'The library needs JavaScript.', 'showbits' ) . '</p></noscript>';
		echo '</div>';
	}
}
