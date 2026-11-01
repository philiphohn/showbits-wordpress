<?php
/**
 * Released clips into the media library.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Imports a released clip or quote card (docs/wordpress.md §5.3): asks ShowBits for the file's link (refused before
 * the embargo ends), downloads it without the token, and sideloads it: a clip's video with its thumbnail as the video's
 * featured image (the poster in the video block), a quote card's picture with the quote as alternative text. A clip,
 * variant and version already in the library stay the one attachment; a new cut (a new version) becomes a new one.
 */
final class Importer {
	public const CLIP_VARIANTS  = array( 'video', '16x9', '9x16' );
	public const QUOTE_VARIANTS = array( '4x5', '9x16', '1x1' );
	/** No file ShowBits makes is near this; filterable for sites that know better (showbits_max_download_bytes). */
	private const MAX_BYTES = 2 * GB_IN_BYTES;

	/**
	 * Imports one clip.
	 *
	 * @param Api_Client  $client  The connection.
	 * @param string      $clip_id The clip.
	 * @param string|null $variant Which file; null: the video, or the quote card's default format.
	 * @param int         $user_id The attachment's author.
	 * @return array{attachment_id: int, url: string, edit_url: string, existing: bool, title: string, variant: string}|\WP_Error
	 */
	public static function import( Api_Client $client, string $clip_id, ?string $variant, int $user_id ) {
		if ( ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $clip_id ) ) {
			return self::error( 'invalid' );
		}
		$item = $client->item( $clip_id );
		if ( is_wp_error( $item ) ) {
			// Not in the library (any more): sent back for changes, deleted, or never released.
			return 'notFound' === Api_Client::reason( $item ) ? self::error( 'notReleased' ) : $item;
		}
		$quote   = 'quote' === ( $item['kind'] ?? '' );
		$variant = $variant ?? ( $quote ? Options::settings()['card_format'] : 'video' );
		if ( ! in_array( $variant, $quote ? self::QUOTE_VARIANTS : self::CLIP_VARIANTS, true ) ) {
			return self::error( 'invalid' );
		}
		if ( ! empty( $item['embargoUntil'] ) ) {
			return self::error( 'embargo' );
		}
		if ( ! self::has_file( $item, $variant ) ) {
			return self::error( 'noFile' );
		}
		$version  = (string) ( $item['version'] ?? '' );
		$existing = self::existing( $clip_id, $variant, $version );
		$id       = $existing ?? self::fetch( $client, $item, $variant, $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( 'video' === $variant && Options::settings()['import_poster'] && ! has_post_thumbnail( $id ) ) {
			$poster = '16x9' === ( $item['format'] ?? '' ) ? '16x9' : '9x16';
			if ( self::has_file( $item, $poster ) ) {
				$poster_id = self::existing( $clip_id, $poster, $version ) ?? self::fetch( $client, $item, $poster, $user_id );
				// The video counts without its poster.
				if ( ! is_wp_error( $poster_id ) ) {
					set_post_thumbnail( $id, $poster_id );
				}
			}
		}
		return array(
			'attachment_id' => $id,
			'url'           => (string) wp_get_attachment_url( $id ),
			'edit_url'      => (string) get_edit_post_link( $id, 'raw' ),
			'existing'      => null !== $existing,
			'title'         => (string) ( $item['title'] ?? '' ),
			'variant'       => $variant,
		);
	}

	/**
	 * Works the queue of pushes (WP-Cron, showbits_process_imports): imports what ShowBits pushed and tells ShowBits
	 * how it went. A minute's work per run; what is left comes in the next one.
	 */
	public static function process_queue(): void {
		$connection = Options::connection();
		$client     = Api_Client::connected();
		if ( ! $connection || ! $client ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- downloads of videos
		}
		$started = time();
		$more    = false;
		while ( true ) {
			// A minute's work per run: what is left comes in the next one.
			if ( time() - $started >= MINUTE_IN_SECONDS ) {
				$more = true;
				break;
			}
			$row = Imports::claim();
			if ( ! $row ) {
				break;
			}
			$user   = (int) $row['user_id'] ? (int) $row['user_id'] : $connection['connected_by'];
			$result = self::import( $client, (string) $row['clip_id'], null, $user );
			if ( is_wp_error( $result ) ) {
				$reason = self::reason_of( $result );
				Imports::finish(
					(int) $row['id'],
					array(
						'status' => 'failed',
						'error'  => $reason,
					)
				);
				$client->report( (string) $row['push_id'], array( 'state' => 'failed', 'reason' => $reason ) );
				continue;
			}
			Imports::finish(
				(int) $row['id'],
				array(
					'status'        => 'imported',
					'attachment_id' => $result['attachment_id'],
					'title'         => $result['title'],
					'variant'       => $result['variant'],
				)
			);
			$url = set_url_scheme( $result['url'], 'https' );
			$client->report( (string) $row['push_id'], str_starts_with( $url, 'https://' ) ? array( 'state' => 'imported', 'attachmentUrl' => $url ) : array( 'state' => 'failed', 'reason' => 'media' ) );
		}
		if ( $more && ! wp_next_scheduled( 'showbits_process_imports' ) ) {
			wp_schedule_single_event( time() + 10, 'showbits_process_imports' );
		}
	}

	/**
	 * What this site already has of these clips: clip id → variant → the attachment and its version.
	 *
	 * @param array<int, string> $clip_ids Clips.
	 * @return array<string, array<string, array{id: int, version: string, edit_url: string}>>
	 */
	public static function imported( array $clip_ids ): array {
		if ( ! $clip_ids ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_showbits_clip_id',
						'value'   => array_values( $clip_ids ),
						'compare' => 'IN',
					),
				),
			)
		);
		$found = array();
		foreach ( $posts as $id ) {
			$clip    = (string) get_post_meta( $id, '_showbits_clip_id', true );
			$variant = (string) get_post_meta( $id, '_showbits_variant', true );
			// The newest attachment of a variant counts (posts come newest first).
			if ( '' === $clip || '' === $variant || isset( $found[ $clip ][ $variant ] ) ) {
				continue;
			}
			$found[ $clip ][ $variant ] = array(
				'id'       => (int) $id,
				'version'  => (string) get_post_meta( $id, '_showbits_version', true ),
				'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
			);
		}
		return $found;
	}

	/**
	 * The reason ShowBits gets for a failed import: its own refusal, or what failed here.
	 *
	 * @param \WP_Error $error What went wrong.
	 */
	public static function reason_of( \WP_Error $error ): string {
		$reason = Api_Client::reason( $error );
		$known  = array( 'embargo', 'notReleased', 'noFile', 'download', 'media', 'invalid', 'unauthorized', 'unreachable' );
		return in_array( $reason, $known, true ) ? $reason : 'failed';
	}

	/**
	 * Whether the clip offers this file now.
	 *
	 * @param array<string, mixed> $item    The library's entry.
	 * @param string               $variant Which file.
	 */
	private static function has_file( array $item, string $variant ): bool {
		foreach ( (array) ( $item['files'] ?? array() ) as $file ) {
			if ( is_array( $file ) && ( $file['variant'] ?? '' ) === $variant ) {
				return true;
			}
		}
		return false;
	}

	/** The attachment of this clip, variant and version, if the library has it. */
	private static function existing( string $clip_id, string $variant, string $version ): ?int {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_showbits_clip_id',
						'value' => $clip_id,
					),
					array(
						'key'   => '_showbits_variant',
						'value' => $variant,
					),
					array(
						'key'   => '_showbits_version',
						'value' => $version,
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : null;
	}

	/**
	 * Downloads one file and puts it into the media library.
	 *
	 * @param Api_Client           $client  The connection.
	 * @param array<string, mixed> $item    The library's entry.
	 * @param string               $variant Which file.
	 * @param int                  $user_id The author.
	 * @return int|\WP_Error The attachment.
	 */
	private static function fetch( Api_Client $client, array $item, string $variant, int $user_id ) {
		// The admin's file and media functions (wp_tempnam, media_handle_sideload): REST requests and WP-Cron do not
		// load them.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$location = $client->file_location( (string) $item['id'], $variant );
		if ( is_wp_error( $location ) ) {
			return $location;
		}
		$file = self::download( $location );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$post = self::attachment_post( $item, $variant, $user_id );
		$id   = media_handle_sideload(
			array(
				'name'     => self::file_name( (string) ( $item['title'] ?? '' ), $variant ),
				'tmp_name' => $file,
			),
			0,
			null,
			$post
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $file );
			return self::error( 'media', $id->get_error_message() );
		}
		foreach ( self::meta( $item, $variant ) as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		$alt = self::alt_text( $item, $variant );
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return (int) $id;
	}

	/**
	 * Downloads a signed link to a temporary file: without the token, only from public addresses (WordPress' safe
	 * request; an OnPremise media store on the inside needs the showbits_allow_private_media filter), no redirects.
	 *
	 * @param string $url The link from ShowBits.
	 * @return string|\WP_Error The temporary file.
	 */
	private static function download( string $url ) {
		$file = wp_tempnam( 'showbits' );
		if ( ! $file ) {
			return self::error( 'download' );
		}
		$args     = array(
			'timeout'             => 300,
			'stream'              => true,
			'filename'            => $file,
			'redirection'         => 0,
			'limit_response_size' => (int) apply_filters( 'showbits_max_download_bytes', self::MAX_BYTES ),
		);
		$response = apply_filters( 'showbits_allow_private_media', false ) ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! filesize( $file ) ) {
			wp_delete_file( $file );
			return self::error( 'download', is_wp_error( $response ) ? $response->get_error_message() : '' );
		}
		return $file;
	}

	/**
	 * Title, caption and description of the attachment.
	 *
	 * @param array<string, mixed> $item    The library's entry.
	 * @param string               $variant Which file.
	 * @param int                  $user_id The author.
	 * @return array<string, mixed>
	 */
	public static function attachment_post( array $item, string $variant, int $user_id ): array {
		$title  = (string) ( $item['title'] ?? '' );
		$source = trim( implode( ' · ', array_filter( array( (string) ( $item['show']['name'] ?? '' ), (string) ( $item['episode']['title'] ?? '' ) ) ) ) );
		$poster = 'video' !== $variant && 'quote' !== ( $item['kind'] ?? '' );
		return array(
			/* translators: %s: the clip's title */
			'post_title'   => $poster ? sprintf( __( '%s (thumbnail)', 'showbits' ), $title ) : $title,
			'post_excerpt' => $poster ? '' : (string) ( $item['caption'] ?? '' ),
			'post_content' => $source,
			'post_author'  => $user_id,
		);
	}

	/**
	 * Where the attachment came from, to find it again.
	 *
	 * @param array<string, mixed> $item    The library's entry.
	 * @param string               $variant Which file.
	 * @return array<string, string>
	 */
	public static function meta( array $item, string $variant ): array {
		$connection = Options::connection();
		return array(
			'_showbits_clip_id'     => (string) $item['id'],
			'_showbits_variant'     => $variant,
			'_showbits_version'     => (string) ( $item['version'] ?? '' ),
			'_showbits_kind'        => (string) ( $item['kind'] ?? '' ),
			'_showbits_workspace'   => $connection ? $connection['workspace_slug'] : '',
			'_showbits_imported_at' => gmdate( 'c' ),
		);
	}

	/**
	 * A quote card's alternative text: what it says and who said it. null for videos and thumbnails.
	 *
	 * @param array<string, mixed> $item    The library's entry.
	 * @param string               $variant Which file.
	 */
	public static function alt_text( array $item, string $variant ): ?string {
		if ( 'quote' !== ( $item['kind'] ?? '' ) || 'video' === $variant ) {
			return null;
		}
		$text = trim( (string) ( $item['caption'] ?? '' ) );
		$name = trim( (string) ( $item['quote']['name'] ?? '' ) );
		/* translators: 1: a quote, 2: who said it */
		return '' === $name ? sprintf( __( '“%s”', 'showbits' ), $text ) : sprintf( __( '“%1$s” – %2$s', 'showbits' ), $text, $name );
	}

	/** The file's name in the media library: the title as a slug, the format for pictures. */
	public static function file_name( string $title, string $variant ): string {
		$slug = sanitize_title( $title );
		$slug = '' === $slug ? 'showbits-clip' : substr( $slug, 0, 80 );
		return 'video' === $variant ? "{$slug}.mp4" : "{$slug}-{$variant}.jpg";
	}

	private static function error( string $reason, string $detail = '' ): \WP_Error {
		$messages = array(
			'invalid'     => __( 'This is not a clip or a format ShowBits offers.', 'showbits' ),
			'notReleased' => __( 'The clip is no longer released in ShowBits.', 'showbits' ),
			'embargo'     => __( 'The embargo still holds: the file stays in ShowBits until it ends.', 'showbits' ),
			'noFile'      => __( 'The file is not ready yet in ShowBits.', 'showbits' ),
			'download'    => __( 'The file could not be downloaded.', 'showbits' ),
			'media'       => __( 'WordPress did not take the file into the media library.', 'showbits' ),
		);
		$message = $messages[ $reason ] ?? __( 'The import failed.', 'showbits' );
		return new \WP_Error( 'showbits_' . $reason, '' === $detail ? $message : $message . ' ' . $detail, array( 'reason' => $reason ) );
	}
}
