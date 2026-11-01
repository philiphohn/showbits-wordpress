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
 * from ShowBits with what this site has of it, an import (asked of ShowBits, which pushes the clip here), and where
 * imports stand (asking works the queue, so imports finish without waiting for WP-Cron). The key never reaches the
 * browser.
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
						'enum' => array( 'video', '4x5', '9x16', '1x1' ),
					),
				),
			)
		);
		register_rest_route(
			'showbits/v1',
			'/imports',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'imports' ),
				'permission_callback' => array( self::class, 'allowed' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[0-9]+(,[0-9]+){0,49}$',
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
	 * Asks ShowBits for one clip, for the person who asked: ShowBits pushes it here with the ticket for its files.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function import( \WP_REST_Request $request ) {
		$client = Api_Client::connected();
		if ( ! $client ) {
			return new \WP_Error( 'showbits_not_connected', __( 'This site is not connected to ShowBits.', 'showbits' ), array( 'status' => 409 ) );
		}
		$variant = $request->get_param( 'variant' );
		$result  = Importer::request( $client, (string) $request->get_param( 'clipId' ), is_string( $variant ) && '' !== $variant && 'video' !== $variant ? $variant : null, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return self::failure( $result );
		}
		return new \WP_REST_Response(
			array(
				'id'      => $result['id'],
				'variant' => $result['variant'],
				'status'  => 'requested',
			),
			202
		);
	}

	/**
	 * Where imports stand. Imports that arrived are worked first, a few seconds' worth.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public static function imports( \WP_REST_Request $request ): \WP_REST_Response {
		if ( Imports::waiting() ) {
			Importer::process_queue( 20 );
		}
		$out = array();
		foreach ( Imports::states( array_map( 'intval', explode( ',', (string) $request->get_param( 'ids' ) ) ) ) as $row ) {
			$attachment = $row['attachment_id'] ? (int) $row['attachment_id'] : null;
			$out[]      = array(
				'id'      => (int) $row['id'],
				'clipId'  => (string) $row['clip_id'],
				'variant' => '' === (string) $row['variant'] ? 'video' : (string) $row['variant'],
				'status'  => (string) $row['status'],
				'error'   => $row['error'] ? (string) $row['error'] : null,
				'editUrl' => $attachment ? (string) get_edit_post_link( $attachment, 'raw' ) : null,
			);
		}
		return new \WP_REST_Response( array( 'imports' => $out ) );
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
			'inactive'     => 403,
			'embargo'      => 409,
			'notReleased'  => 409,
			'noFile'       => 404,
			'limit'        => 429,
			'tooMany'      => 429,
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
