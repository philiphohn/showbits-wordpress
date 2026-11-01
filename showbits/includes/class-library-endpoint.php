<?php
/**
 * The library page's own REST routes.
 *
 * @package ShowBits
 */

namespace ShowBits\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The browser on Media → ShowBits talks to these (WordPress' cookie and nonce, like the block editor): the library
 * from ShowBits with what this site has of it, and an import. The token never reaches the browser.
 */
final class Library_Endpoint {
	public static function register(): void {
		register_rest_route(
			'showbits/v1',
			'/library',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'library' ),
				'permission_callback' => array( self::class, 'allowed' ),
				'args'                => array(
					'kind'   => array(
						'type' => 'string',
						'enum' => array( '', 'clip', 'quote' ),
					),
					'q'      => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'cursor' => array(
						'type'      => 'string',
						'maxLength' => 200,
						'pattern'   => '^[A-Za-z0-9_-]*$',
					),
				),
			)
		);
		register_rest_route(
			'showbits/v1',
			'/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'import' ),
				'permission_callback' => array( self::class, 'allowed' ),
				'args'                => array(
					'clipId'  => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9-]{1,64}$',
					),
					'variant' => array(
						'type' => 'string',
						'enum' => array( 'video', '16x9', '9x16', '4x5', '1x1' ),
					),
				),
			)
		);
	}

	/** People who may upload to the media library (filter showbits_capability). */
	public static function allowed(): bool {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * A page of released clips, each with what this site has of it already.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function library( \WP_REST_Request $request ) {
		$client = Api_Client::connected();
		if ( ! $client ) {
			return new \WP_Error( 'showbits_not_connected', __( 'This site is not connected to ShowBits.', 'showbits' ), array( 'status' => 409 ) );
		}
		$page = $client->library(
			array(
				'kind'   => (string) $request->get_param( 'kind' ),
				'q'      => sanitize_text_field( (string) $request->get_param( 'q' ) ),
				'cursor' => (string) $request->get_param( 'cursor' ),
				'limit'  => 24,
			)
		);
		if ( is_wp_error( $page ) ) {
			return self::failure( $page );
		}
		$items    = array_values( array_filter( (array) ( $page['data'] ?? array() ), 'is_array' ) );
		$imported = Importer::imported( array_map( static fn( array $item ): string => (string) ( $item['id'] ?? '' ), $items ) );
		foreach ( $items as &$item ) {
			$have = array();
			foreach ( $imported[ (string) $item['id'] ] ?? array() as $variant => $attachment ) {
				$have[ $variant ] = array(
					'id'      => $attachment['id'],
					'editUrl' => $attachment['edit_url'],
					// An older version: ShowBits has a new cut or drawing since.
					'current' => $attachment['version'] === (string) ( $item['version'] ?? '' ),
				);
			}
			$item['imported'] = (object) $have;
		}
		unset( $item );
		return new \WP_REST_Response(
			array(
				'items'      => $items,
				'nextCursor' => $page['nextCursor'] ?? null,
			)
		);
	}

	/**
	 * Imports one clip now, for the person who asked.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( \WP_REST_Request $request ) {
		$client = Api_Client::connected();
		if ( ! $client ) {
			return new \WP_Error( 'showbits_not_connected', __( 'This site is not connected to ShowBits.', 'showbits' ), array( 'status' => 409 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- downloads of videos
		}
		$clip    = (string) $request->get_param( 'clipId' );
		$variant = $request->get_param( 'variant' );
		$result  = Importer::import( $client, $clip, is_string( $variant ) && '' !== $variant ? $variant : null, get_current_user_id() );
		Imports::record(
			array(
				'clip_id'       => $clip,
				'variant'       => is_wp_error( $result ) ? ( is_string( $variant ) ? $variant : '' ) : $result['variant'],
				'title'         => is_wp_error( $result ) ? '' : $result['title'],
				'status'        => is_wp_error( $result ) ? 'failed' : 'imported',
				'attachment_id' => is_wp_error( $result ) ? null : $result['attachment_id'],
				'error'         => is_wp_error( $result ) ? Importer::reason_of( $result ) : null,
				'user_id'       => get_current_user_id(),
			)
		);
		if ( is_wp_error( $result ) ) {
			return self::failure( $result );
		}
		return new \WP_REST_Response(
			array(
				'attachmentId' => $result['attachment_id'],
				'editUrl'      => $result['edit_url'],
				'url'          => $result['url'],
				'existing'     => $result['existing'],
				'variant'      => $result['variant'],
			)
		);
	}

	/**
	 * An error for the browser: the message, ShowBits' reason, a status that says what kind.
	 *
	 * @param \WP_Error $error What went wrong.
	 */
	private static function failure( \WP_Error $error ): \WP_Error {
		$reason = Api_Client::reason( $error );
		$status = array(
			'unauthorized' => 401,
			'embargo'      => 409,
			'notReleased'  => 409,
			'noFile'       => 404,
			'unreachable'  => 502,
			'invalid'      => 422,
		);
		return new \WP_Error(
			'showbits_' . $reason,
			'unauthorized' === $reason ? __( 'ShowBits no longer accepts this site’s connection: connect it again under Settings → ShowBits.', 'showbits' ) : $error->get_error_message(),
			array(
				'status' => $status[ $reason ] ?? 502,
				'reason' => $reason,
			)
		);
	}
}
